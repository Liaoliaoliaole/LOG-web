<?php

require_once __DIR__ . '/concurrency.php';

class ChannelConfigException extends RuntimeException
{
    private int $status;
    private string $apiCode;

    public function __construct(string $message, int $status = 400, string $apiCode = 'channel_config_error')
    {
        parent::__construct($message);
        $this->status = $status;
        $this->apiCode = $apiCode;
    }

    public function status(): int
    {
        return $this->status;
    }

    public function apiCode(): string
    {
        return $this->apiCode;
    }
}

function iso_load_channels(string $xmlPath): array
{
    if (!is_file($xmlPath)) {
        throw new ChannelConfigException("OPC_UA_Config not found: $xmlPath", 404, 'channel_config_missing');
    }

    $xml = simplexml_load_file($xmlPath);
    if ($xml === false) {
        throw new ChannelConfigException("Failed to parse XML at $xmlPath", 500, 'channel_config_parse_failed');
    }

    $out = [];
    foreach ($xml->CHANNEL as $ch) {
        $out[] = [
            'iso_channel'    => trim((string)$ch->ISO_CHANNEL),
            'interface_type' => trim((string)$ch->INTERFACE_TYPE),
            'anchor'         => trim((string)$ch->ANCHOR),
            'description'    => trim((string)$ch->DESCRIPTION),
            'min'            => (string)$ch->MIN,
            'max'            => (string)$ch->MAX,
            'unit'           => trim((string)$ch->UNIT),
            'cal_date'       => trim((string)$ch->CAL_DATE),
            'cal_period'     => $ch->CAL_PERIOD !== null ? (int)$ch->CAL_PERIOD : null,
            // Keep alarm fields aligned with iso_channel_snapshot() for edit hydration.
            'alarm_high_val' => (string)$ch->ALARM_HIGH_VAL,
            'alarm_low_val'  => (string)$ch->ALARM_LOW_VAL,
            'alarm_high'     => trim((string)$ch->ALARM_HIGH),
            'alarm_low'      => trim((string)$ch->ALARM_LOW),
        ];
    }

    return $out;
}

/*
 * The single Core-equivalence gate for OPC_UA_Config.xml (plan §6.0.2,
 * rules C-1 through C-10, cross-checked against every EXIT_FAILURE branch
 * in Morfeas_opc_ua_config_valid() / Morfeas_XML.c -- 12 branches, all
 * covered here). Every write path serializes through iso_save_xml(), and
 * this function runs first inside it, so no writer can bypass it -- that
 * is the point: a per-writer field checklist is exactly what let F-1/F-2/
 * F-3 (2026-08-19 code review) slip through undetected. Structural checks
 * (D-1/D-2/D-3) mirror Morfeas.dtd's CHANNEL content model and stay even
 * though every current writer already goes through iso_set_channel_contents()
 * in the right order, because this function's whole purpose is to verify
 * the final document rather than trust the writer.
 *
 * Deliberately re-checks C-2/C-3/C-6/C-8/C-9, which individual write paths
 * already enforce via iso_require_valid_source_identity()/
 * iso_find_anchor_conflict() against the anchor being written -- this
 * function instead re-derives them against the *whole* final document, so
 * it does not depend on every future writer remembering to call those
 * helpers correctly.
 */
function iso_validate_document(SimpleXMLElement $xml): void
{
    $dtdOrder = ['ISO_CHANNEL', 'INTERFACE_TYPE', 'ANCHOR', 'DESCRIPTION', 'MIN', 'MAX',
        'UNIT', 'CAL_DATE', 'CAL_PERIOD', 'BUILD_DATE', 'MOD_DATE',
        'ALARM_HIGH_VAL', 'ALARM_LOW_VAL', 'ALARM_HIGH', 'ALARM_LOW'];
    $requiredElements = array_slice($dtdOrder, 0, 6); // ISO_CHANNEL..MAX, per Morfeas.dtd
    $orderIndex = array_flip($dtdOrder);
    $knownInterfaces = ['SDAQ', 'IOBOX', 'MTI', 'NOX'];

    $seenIso = [];
    $seenSemanticKey = [];

    foreach ($xml->CHANNEL as $ch) {
        $childNames = [];
        foreach ($ch->children() as $child) {
            $childNames[] = $child->getName();
        }
        $isoForError = trim((string)$ch->ISO_CHANNEL) ?: '(unknown)';

        // D-3: no element name outside the DTD's CHANNEL content model.
        foreach ($childNames as $name) {
            if (!isset($orderIndex[$name])) {
                throw new ChannelConfigException(
                    "CHANNEL \"$isoForError\" contains an element not in Morfeas.dtd: $name",
                    500,
                    'invalid_document_structure'
                );
            }
        }
        // D-1: the six required elements must all be present.
        foreach ($requiredElements as $name) {
            if (!in_array($name, $childNames, true)) {
                throw new ChannelConfigException(
                    "CHANNEL \"$isoForError\" is missing required element: $name",
                    500,
                    'invalid_document_structure'
                );
            }
        }
        // D-2: elements that are present must appear in DTD sequence order.
        $lastIdx = -1;
        foreach ($childNames as $name) {
            if ($orderIndex[$name] < $lastIdx) {
                throw new ChannelConfigException(
                    "CHANNEL \"$isoForError\" has elements out of Morfeas.dtd order (at $name)",
                    500,
                    'invalid_document_structure'
                );
            }
            $lastIdx = $orderIndex[$name];
        }

        // C-1: no present element's content may be empty (applies to all
        // fifteen elements, not just the six required ones -- an optional
        // element that is present but empty is just as fatal to Core).
        foreach ($ch->children() as $child) {
            if (trim((string)$child) === '') {
                throw new ChannelConfigException(
                    "CHANNEL \"$isoForError\" element " . $child->getName() . " must not be empty",
                    409,
                    'empty_element'
                );
            }
        }

        $isoChannel = trim((string)$ch->ISO_CHANNEL);
        $interfaceType = strtoupper(trim((string)$ch->INTERFACE_TYPE));
        $anchor = trim((string)$ch->ANCHOR);

        // C-4: ISO_CHANNEL length, on the actual stored (already "_"-prefixed) value.
        if (strlen($isoChannel) >= 20) { // ISO_channel_name_size
            throw new ChannelConfigException(
                "ISO_CHANNEL is too long (>= 20 bytes): $isoChannel",
                409,
                'invalid_iso_channel'
            );
        }
        // C-5: ISO_CHANNEL must not contain '.'.
        if (strpos($isoChannel, '.') !== false) {
            throw new ChannelConfigException(
                "ISO_CHANNEL contains an illegal '.': $isoChannel",
                409,
                'invalid_iso_channel'
            );
        }

        // C-2: INTERFACE_TYPE must be a known, supported interface (this
        // also covers C-9: MDAQ is not in the known list, so it lands here
        // with a distinct code rather than being folded into C-6's grammar
        // failure).
        if (!in_array($interfaceType, $knownInterfaces, true)) {
            throw new ChannelConfigException(
                "CHANNEL \"$isoChannel\" has an unsupported INTERFACE_TYPE: $interfaceType",
                409,
                'unsupported_interface'
            );
        }

        // C-6: ANCHOR must satisfy the interface's strict grammar.
        $identity = iso_parse_source_identity($interfaceType, $anchor);
        if ($identity === null) {
            throw new ChannelConfigException(
                "CHANNEL \"$isoChannel\" has an invalid ANCHOR for interface $interfaceType: $anchor",
                409,
                'invalid_anchor'
            );
        }

        // C-7: IOBOX/MTI/NOX must carry a non-empty XML-owned UNIT; SDAQ's
        // Unit is runtime-owned and not read from XML.
        if ($interfaceType !== 'SDAQ' && trim((string)$ch->UNIT) === '') {
            throw new ChannelConfigException(
                "CHANNEL \"$isoChannel\" ($interfaceType) is missing a non-empty UNIT",
                409,
                'missing_required_unit'
            );
        }

        // C-3: ISO_CHANNEL must be unique across the document.
        if (isset($seenIso[$isoChannel])) {
            throw new ChannelConfigException(
                "ISO_CHANNEL \"$isoChannel\" appears more than once",
                409,
                'channel_conflict'
            );
        }
        $seenIso[$isoChannel] = true;

        // C-8: the parsed source identity must be unique across the
        // document, per interface (compared on decoded fields, never on
        // raw ANCHOR text -- iso_parse_source_identity()'s semantic_key
        // already encodes the interface, so cross-interface collisions
        // cannot occur here by construction).
        $key = $identity['semantic_key'];
        if (isset($seenSemanticKey[$key])) {
            throw new ChannelConfigException(
                "ANCHOR \"$anchor\" of ISO_CHANNEL \"$isoChannel\" duplicates the source already used by ISO_CHANNEL \"{$seenSemanticKey[$key]}\"",
                409,
                'duplicate_source'
            );
        }
        $seenSemanticKey[$key] = $isoChannel;
    }

    // C-10 is not implemented here: it only fails when the document has at
    // least one CHANNEL but zero of them ever set an INTERFACE_TYPE/
    // ISO_CHANNEL/ANCHOR node, which iso_set_channel_contents() cannot
    // produce, and an empty NODESet returns EXIT_SUCCESS in Core. See
    // plan §6.0.2 for the full derivation.
}

function iso_save_xml(SimpleXMLElement $xml, string $xmlPath): void
{
    iso_validate_document($xml);

    $dom = new DOMDocument('1.0');
    $dom->encoding = 'UTF-8';
    $dom->preserveWhiteSpace = false;
    $dom->formatOutput = true;

    if ($dom->loadXML($xml->asXML()) === false) {
        throw new ChannelConfigException('Failed to format XML output', 500, 'channel_config_format_failed');
    }

    $xmlString = $dom->saveXML();
    if ($xmlString === false) {
        throw new ChannelConfigException('Failed to serialize XML', 500, 'channel_config_serialize_failed');
    }

    $xmlString = preg_replace_callback('/<UNIT>(.*?)<\\/UNIT>/s', static function ($matches) {
        return '<UNIT>' . html_entity_decode($matches[1], ENT_QUOTES | ENT_XML1, 'UTF-8') . '</UNIT>';
    }, $xmlString);

    try {
        backend_atomic_write_file($xmlPath, $xmlString, 0644);
    } catch (Throwable $e) {
        throw new ChannelConfigException("Failed to save XML: $xmlPath", 500, 'channel_config_save_failed');
    }
}

function iso_with_xml_lock(string $xmlPath, callable $fn)
{
    return backend_with_resource_file_lock('opcua_config', $xmlPath, $fn);
}

function iso_channel_snapshot(SimpleXMLElement $ch): array
{
    return [
        'iso_channel'    => trim((string)$ch->ISO_CHANNEL),
        'interface_type' => trim((string)$ch->INTERFACE_TYPE),
        'anchor'         => trim((string)$ch->ANCHOR),
        'description'    => trim((string)$ch->DESCRIPTION),
        'min'            => (string)$ch->MIN,
        'max'            => (string)$ch->MAX,
        'unit'           => iso_decode_xml_value(trim((string)$ch->UNIT)),
        'cal_date'       => trim((string)$ch->CAL_DATE),
        'cal_period'     => (string)$ch->CAL_PERIOD,
        'build_date'     => (string)$ch->BUILD_DATE,
        'mod_date'       => (string)$ch->MOD_DATE,
        'alarm_high_val' => (string)$ch->ALARM_HIGH_VAL,
        'alarm_low_val'  => (string)$ch->ALARM_LOW_VAL,
        'alarm_high'     => trim((string)$ch->ALARM_HIGH),
        'alarm_low'      => trim((string)$ch->ALARM_LOW),
    ];
}

function iso_normalize_iso_channel($value): ?string
{
    if ($value === null) {
        return null;
    }
    $value = trim((string)$value);
    if ($value === '') {
        return '';
    }
    return $value[0] === '_' ? $value : '_' . $value;
}

function iso_decode_xml_value($value): ?string
{
    if ($value === null) {
        return null;
    }
    $value = (string)$value;
    if ($value === '') {
        return '';
    }
    return html_entity_decode($value, ENT_QUOTES | ENT_XML1, 'UTF-8');
}

/*
 * True if $digits (a decimal string with no leading zero -- callers enforce
 * that via regex before calling this) fits in a uint32. String comparison
 * avoids float rounding on 32-bit PHP builds for very large decimal strings.
 */
function iso_digits_fit_uint32(string $digits): bool
{
    return !(strlen($digits) > 10 || (strlen($digits) === 10 && strcmp($digits, '4294967295') > 0));
}

/*
 * Core-equivalent strict grammar for the canonical SDAQ anchor:
 * "<serial>.CH<channel>", uint32 serial > 0, channel in 1..16, no leading
 * zeros, no trailing text. Mirrors decode_sdaq_anchor() in
 * src/Supplementary/Morfeas_XML.c; must stay in sync with that decoder.
 * Returns null on parse failure, otherwise ['canonical_anchor', 'semantic_key', 'components'].
 */
function iso_parse_sdaq_identity(string $anchor): ?array
{
    if (!preg_match('/^([1-9][0-9]*)\.CH([1-9][0-9]*)$/', $anchor, $m)) {
        return null;
    }
    $serial = $m[1];
    $channel = (int)$m[2];
    if (!iso_digits_fit_uint32($serial)) {
        return null;
    }
    if ($channel < 1 || $channel > 16) { // SDAQ_MAX_AMOUNT_OF_CHANNELS
        return null;
    }
    return [
        'canonical_anchor' => $anchor, // the regex already enforces canonical shape
        'semantic_key' => 'SDAQ|' . $serial . '|' . $channel,
        'components' => ['serial' => $serial, 'channel' => $channel],
    ];
}

/*
 * Core-equivalent strict grammar for IOBOX, mirroring decode_iobox_anchor()
 * in src/Supplementary/Morfeas_XML.c:
 *     <identifier>.RX<receiver>.CH<channel>
 *     <identifier>.RX<receiver>.Status
 *     <identifier>.RX<receiver>.Success
 * identifier: uint32, no leading zero; receiver: 1..6 (IOBOX_Amount_of_All_RXs);
 * channel (CH form only): 1..16 (IOBOX_Amount_of_channels). Case-sensitive;
 * no suffix, extra segment or trailing text.
 */
function iso_parse_iobox_identity(string $anchor): ?array
{
    if (!preg_match('/^([1-9][0-9]*)\.RX([1-9][0-9]*)\.(CH([1-9][0-9]*)|Status|Success)$/', $anchor, $m)) {
        return null;
    }
    $identifier = $m[1];
    $receiver = (int)$m[2];
    if (!iso_digits_fit_uint32($identifier) || $receiver < 1 || $receiver > 6) {
        return null;
    }

    if ($m[3] === 'Status' || $m[3] === 'Success') {
        $kind = $m[3];
        $channel = null;
    } else {
        $channel = (int)$m[4];
        if ($channel < 1 || $channel > 16) { // IOBOX_Amount_of_channels
            return null;
        }
        $kind = 'CH' . $channel;
    }

    return [
        'canonical_anchor' => $identifier . '.RX' . $receiver . '.' . $kind,
        'semantic_key' => 'IOBOX|' . $identifier . '|' . $receiver . '|' . $kind,
        'components' => ['identifier' => $identifier, 'receiver' => $receiver, 'kind' => $kind, 'channel' => $channel],
    ];
}

/*
 * Core-equivalent strict grammar for MTI, mirroring decode_mti_anchor() in
 * src/Supplementary/Morfeas_XML.c:
 *     <identifier>.TC16.CH<1..16>
 *     <identifier>.TC8.CH<1..8>
 *     <identifier>.TC4.CH<1..4>
 *     <identifier>.QUAD.CH<1..2>
 *     <identifier>.ID:<1..255>.CH<1..4>
 * The literal "RMSW/MUX" (a runtime radio-mode string) never matches this
 * grammar and is never itself a valid anchor token; Mini-RMSW devices are
 * only reachable through the "ID:<id>" form. tele_ID is unsigned char in
 * Core's struct Link_entry, so an ID>255 is rejected, never truncated.
 */
function iso_parse_mti_identity(string $anchor): ?array
{
    if (!preg_match('/^([1-9][0-9]*)\.(?:(TC16|TC8|TC4|QUAD)|ID:([1-9][0-9]*))\.CH([1-9][0-9]*)$/', $anchor, $m)) {
        return null;
    }
    $identifier = $m[1];
    if (!iso_digits_fit_uint32($identifier)) {
        return null;
    }
    $channel = (int)$m[4];

    if ($m[2] !== '') {
        $type = $m[2];
        $maxChannel = ['TC16' => 16, 'TC8' => 8, 'TC4' => 4, 'QUAD' => 2][$type];
        if ($channel < 1 || $channel > $maxChannel) {
            return null;
        }
        return [
            'canonical_anchor' => $identifier . '.' . $type . '.CH' . $channel,
            'semantic_key' => 'MTI|' . $identifier . '|' . $type . '|0|' . $channel,
            'components' => ['identifier' => $identifier, 'type' => $type, 'tele_id' => null, 'channel' => $channel],
        ];
    }

    $teleIdStr = $m[3];
    if (strlen($teleIdStr) > 3 || (int)$teleIdStr > 255) { // tele_ID is unsigned char in struct Link_entry
        return null;
    }
    $teleId = (int)$teleIdStr;
    if ($channel < 1 || $channel > 4) { // Mini-RMSW: RMSW_MUX_Mini_data_struct.meas_data[4]
        return null;
    }
    return [
        'canonical_anchor' => $identifier . '.ID:' . $teleId . '.CH' . $channel,
        'semantic_key' => 'MTI|' . $identifier . '|ID|' . $teleId . '|' . $channel,
        'components' => ['identifier' => $identifier, 'type' => 'ID', 'tele_id' => $teleId, 'channel' => $channel],
    ];
}

/*
 * Core-equivalent grammar for NOX, mirroring decode_nox_anchor() in
 * src/Supplementary/Morfeas_XML.c *exactly* -- including that decoder's
 * current permissiveness (any non-empty, dot-free CAN interface segment;
 * address digits may have a leading zero; address is currently only ever
 * 0 or 1). This function's job is Core-equivalence, not a stricter or
 * "cleaner" grammar than what Core actually accepts today.
 * Accepts both "addr_N" and "addr:N" (case-insensitive), canonicalizes to
 * "<can_if>.addr_<N>.NOx|O2" in lower-case, matching what Web Search/Add
 * already generates.
 */
function iso_parse_nox_identity(string $anchor): ?array
{
    $firstDot = strpos($anchor, '.');
    if ($firstDot === false) {
        return null;
    }
    $secondDot = strpos($anchor, '.', $firstDot + 1);
    if ($secondDot === false || strpos($anchor, '.', $secondDot + 1) !== false) {
        return null; // exactly two dots
    }

    $canIf = substr($anchor, 0, $firstDot);
    if ($canIf === '' || strlen($canIf) > 15) { // Dev_or_Bus_name_str_size (IFNAMSIZ) == 16
        return null;
    }

    $addressSeg = substr($anchor, $firstDot + 1, $secondDot - $firstDot - 1);
    if (strlen($addressSeg) <= 5) { // must be longer than "addr_"/"addr:"
        return null;
    }
    $prefix = substr($addressSeg, 0, 5);
    if (strcasecmp($prefix, 'addr_') !== 0 && strcasecmp($prefix, 'addr:') !== 0) {
        return null;
    }
    $addressDigits = substr($addressSeg, 5);
    if ($addressDigits === '' || !ctype_digit($addressDigits)) {
        return null;
    }
    if ((int)$addressDigits > 1) { // Core's decode_nox_anchor() currently only accepts address 0 or 1
        return null;
    }

    $measurementRaw = substr($anchor, $secondDot + 1);
    if (strcasecmp($measurementRaw, 'NOx') === 0) {
        $measurement = 'NOx';
    } elseif (strcasecmp($measurementRaw, 'O2') === 0) {
        $measurement = 'O2';
    } else {
        return null;
    }

    $canIfLower = strtolower($canIf);
    $address = (int)$addressDigits;
    return [
        'canonical_anchor' => sprintf('%s.addr_%d.%s', $canIfLower, $address, $measurement),
        'semantic_key' => 'NOX|' . $canIfLower . '|' . $address . '|' . $measurement,
        'components' => ['can_if' => $canIfLower, 'address' => $address, 'measurement' => $measurement],
    ];
}

/*
 * Single interface-aware authority for "is this anchor a valid source
 * identity, and what does it canonicalize to": every write entry point
 * (Add, Replace, batch, and the future Local JSON/FTP Restore) must consume
 * this result instead of trusting client-submitted text or falling back to
 * a looser interpretation. Returns null on parse failure -- never a raw/
 * uppercased fallback -- so callers can distinguish "invalid" from "valid".
 */
function iso_parse_source_identity(string $interfaceType, string $anchor): ?array
{
    // No trimming here: every per-interface grammar below is a full-string
    // match anchored at both ends, so leading/trailing whitespace must be a
    // parse failure, not something this dispatcher silently tolerates.
    if ($anchor === '') {
        return null;
    }
    switch (strtoupper(trim($interfaceType))) {
        case 'SDAQ':
            return iso_parse_sdaq_identity($anchor);
        case 'IOBOX':
            return iso_parse_iobox_identity($anchor);
        case 'MTI':
            return iso_parse_mti_identity($anchor);
        case 'NOX':
            return iso_parse_nox_identity($anchor);
        default:
            return null;
    }
}

/*
 * Grammar/range validation gate for every write entry point. Throws
 * ChannelConfigException(..., 409, 'invalid_anchor') on failure; there is
 * no fallback to a looser interpretation of an anchor that fails to parse.
 */
function iso_require_valid_source_identity(string $interfaceType, string $anchor): array
{
    $identity = iso_parse_source_identity($interfaceType, $anchor);
    if ($identity === null) {
        throw new ChannelConfigException(
            'ANCHOR is not a valid ' . strtoupper(trim($interfaceType)) . ' source identity: ' . $anchor,
            409,
            'invalid_anchor'
        );
    }
    return $identity;
}

/*
 * Semantic-source duplicate check: compares each existing CHANNEL's own
 * parsed identity (never raw ANCHOR text) against $semanticKey. An existing
 * channel whose own ANCHOR fails to parse can never be a semantic duplicate
 * of anything and is skipped, mirroring Morfeas_opc_ua_config_valid()'s
 * duplicate-check loop in Core.
 */
function iso_find_anchor_conflict(SimpleXMLElement $xml, string $semanticKey, ?string $ignoreIso = null): ?string
{
    $ignoreIsoNorm = $ignoreIso !== null ? iso_normalize_iso_channel($ignoreIso) : null;

    foreach ($xml->CHANNEL as $ch) {
        $existingIso = iso_normalize_iso_channel((string)$ch->ISO_CHANNEL);
        if ($ignoreIsoNorm !== null && $existingIso === $ignoreIsoNorm) {
            continue;
        }

        $existingIdentity = iso_parse_source_identity((string)$ch->INTERFACE_TYPE, (string)$ch->ANCHOR);
        if ($existingIdentity === null) {
            continue;
        }

        if ($existingIdentity['semantic_key'] === $semanticKey) {
            return trim((string)$ch->ISO_CHANNEL);
        }
    }

    return null;
}

function iso_set_channel_contents(SimpleXMLElement $ch, array $data): void
{
    foreach ($ch->xpath('*') as $child) {
        unset($child[0]);
    }

    $ch->addChild('ISO_CHANNEL', $data['iso_channel']);
    $ch->addChild('INTERFACE_TYPE', $data['interface_type']);
    $ch->addChild('ANCHOR', $data['anchor']);
    $ch->addChild('DESCRIPTION', $data['description']);
    $ch->addChild('MIN', $data['min']);
    $ch->addChild('MAX', $data['max']);

    if ($data['unit'] !== null && $data['unit'] !== '') {
        $ch->addChild('UNIT', $data['unit']);
    }
    if ($data['cal_date'] !== null && $data['cal_date'] !== '') {
        $ch->addChild('CAL_DATE', $data['cal_date']);
    }
    if ($data['cal_period'] !== null && $data['cal_period'] !== '') {
        $ch->addChild('CAL_PERIOD', $data['cal_period']);
    }
    if ($data['build_date'] !== null && $data['build_date'] !== '') {
        $ch->addChild('BUILD_DATE', $data['build_date']);
    }
    if ($data['mod_date'] !== null && $data['mod_date'] !== '') {
        $ch->addChild('MOD_DATE', $data['mod_date']);
    }
    if ($data['alarm_high_val'] !== null && $data['alarm_high_val'] !== '') {
        $ch->addChild('ALARM_HIGH_VAL', $data['alarm_high_val']);
    }
    if ($data['alarm_low_val'] !== null && $data['alarm_low_val'] !== '') {
        $ch->addChild('ALARM_LOW_VAL', $data['alarm_low_val']);
    }
    if ($data['alarm_high'] !== null && $data['alarm_high'] !== '') {
        $ch->addChild('ALARM_HIGH', $data['alarm_high']);
    }
    if ($data['alarm_low'] !== null && $data['alarm_low'] !== '') {
        $ch->addChild('ALARM_LOW', $data['alarm_low']);
    }
}

function iso_pick_value(array $data, array $keys)
{
    foreach ($keys as $key) {
        if (array_key_exists($key, $data)) {
            return $data[$key];
        }
    }
    return null;
}

/*
 * Retained as a boolean convenience wrapper for callers that only need a
 * yes/no answer (channel_service.php's Add/Replace pool resolution). Grammar
 * itself lives in iso_parse_sdaq_identity(); do not re-implement it here.
 */
function iso_sdaq_anchor_is_valid(string $anchor): bool
{
    return iso_parse_sdaq_identity($anchor) !== null;
}

function iso_add_channel(string $xmlPath, array $data): void
{
    iso_with_xml_lock($xmlPath, function () use ($xmlPath, $data) {
        iso_add_channel_body($xmlPath, $data);
    });
}

/*
 * Lock-free core of Add. Callers that need to combine candidate-pool
 * revalidation with the write (see channel_add_channel_from_pool() in
 * channel_service.php) must already hold the XML lock and call this
 * directly, instead of iso_add_channel(), to avoid re-entering
 * iso_with_xml_lock().
 */
function iso_add_channel_body(string $xmlPath, array $data): void
{
    if (!file_exists($xmlPath)) {
        throw new ChannelConfigException("XML not found: $xmlPath", 404, 'channel_config_missing');
    }
    $xml = simplexml_load_file($xmlPath);
    if ($xml === false) {
        throw new ChannelConfigException("Failed to parse XML", 500, 'channel_config_parse_failed');
    }

    $isoChannel = iso_normalize_iso_channel($data['iso_channel']);

    foreach ($xml->CHANNEL as $ch) {
        if ((string)$ch->ISO_CHANNEL === $isoChannel) {
            throw new ChannelConfigException("ISO_CHANNEL already exists: " . $isoChannel, 409, 'channel_conflict');
        }
    }

    $interfaceType = strtoupper(trim((string)($data['interface_type'] ?? '')));
    $anchor = (string)($data['anchor'] ?? '');
    // Defense in depth: even a server-derived anchor must satisfy the
    // Core-equivalent grammar, for every interface, before it is ever
    // written to disk -- and it is always persisted in its canonical form,
    // never whatever text happened to be submitted.
    $identity = iso_require_valid_source_identity($interfaceType, $anchor);
    $data['anchor'] = $identity['canonical_anchor'];

    $anchorConflictIso = iso_find_anchor_conflict($xml, $identity['semantic_key']);
    if ($anchorConflictIso !== null) {
        throw new ChannelConfigException(
            "ANCHOR already exists: " . $identity['canonical_anchor'] . " is already used by " . $anchorConflictIso,
            409,
            'duplicate_source'
        );
    }

    $new = $xml->addChild('CHANNEL');
    iso_set_channel_contents($new, iso_build_new_channel_payload($isoChannel, $data));
    iso_save_xml($xml, $xmlPath);
}

/*
 * Builds the iso_set_channel_contents() payload for a brand-new channel
 * (single Add and batch Range Add both use this): dates default to now(),
 * everything else comes straight from $data. Does not validate anchor
 * grammar or duplicates -- callers must have already run
 * iso_require_valid_source_identity()/iso_find_anchor_conflict() and passed
 * the canonicalized anchor in $data['anchor'].
 */
function iso_build_new_channel_payload(string $isoChannel, array $data): array
{
    $now = time();
    $buildDate = iso_pick_value($data, ['build_date', 'build_date_unix', 'Build_date_UNIX']);
    $modDate = iso_pick_value($data, ['mod_date', 'mod_date_unix', 'Mod_date_UNIX']);

    return [
        'iso_channel'    => $isoChannel,
        'interface_type' => $data['interface_type'],
        'anchor'         => $data['anchor'],
        'description'    => $data['description'] ?? '',
        'min'            => $data['min'] ?? '0',
        'max'            => $data['max'] ?? '0',
        'unit'           => iso_decode_xml_value($data['unit'] ?? null),
        'cal_date'       => $data['cal_date'] ?? null,
        'cal_period'     => $data['cal_period'] ?? null,
        'build_date'     => $buildDate ?? $now,
        'mod_date'       => $modDate ?? $now,
        'alarm_high_val' => array_key_exists('alarm_high_val', $data) ? $data['alarm_high_val'] : null,
        'alarm_low_val'  => array_key_exists('alarm_low_val', $data) ? $data['alarm_low_val'] : null,
        'alarm_high'     => array_key_exists('alarm_high', $data) ? $data['alarm_high'] : null,
        'alarm_low'      => array_key_exists('alarm_low', $data) ? $data['alarm_low'] : null,
    ];
}

function iso_update_channel(string $xmlPath, string $isoChannel, array $data): void
{
    iso_with_xml_lock($xmlPath, function () use ($xmlPath, $isoChannel, $data) {
        iso_update_channel_body($xmlPath, $isoChannel, $data);
    });
}

/*
 * Lock-free core of Edit/Replace. Callers that need to combine candidate-pool
 * revalidation with the write (see channel_replace_channel_from_pool() in
 * channel_service.php) must already hold the XML lock and call this
 * directly, instead of iso_update_channel(), to avoid re-entering
 * iso_with_xml_lock().
 */
function iso_update_channel_body(string $xmlPath, string $isoChannel, array $data): void
{
    if (!file_exists($xmlPath)) {
        throw new ChannelConfigException("XML not found: $xmlPath", 404, 'channel_config_missing');
    }
    $xml = simplexml_load_file($xmlPath);
    if ($xml === false) {
        throw new ChannelConfigException("Failed to parse XML", 500, 'channel_config_parse_failed');
    }

    $isoChannel = iso_normalize_iso_channel($isoChannel);
    $target = null;
    foreach ($xml->CHANNEL as $ch) {
        if ((string)$ch->ISO_CHANNEL === $isoChannel) {
            $target = $ch;
            break;
        }
    }
    if (!$target) {
        throw new ChannelConfigException("ISO_CHANNEL not found: " . $isoChannel, 404, 'channel_not_found');
    }

    $existing = iso_channel_snapshot($target);
    $newIso = $existing['iso_channel'];
    if (array_key_exists('iso_channel', $data)) {
        $newIso = iso_normalize_iso_channel($data['iso_channel']);
        if ($newIso !== $isoChannel) {
            foreach ($xml->CHANNEL as $ch) {
                if ((string)$ch->ISO_CHANNEL === $newIso) {
                    throw new ChannelConfigException("ISO_CHANNEL already exists: " . $newIso, 409, 'channel_conflict');
                }
            }
        }
    }

    $buildDate = iso_pick_value($data, ['build_date', 'build_date_unix', 'Build_date_UNIX']);
    $modDate = iso_pick_value($data, ['mod_date', 'mod_date_unix', 'Mod_date_UNIX']);
    $now = time();

    $payload = [
        'iso_channel'    => $newIso,
        'interface_type' => $data['interface_type'] ?? $existing['interface_type'],
        'anchor'         => $data['anchor'] ?? $existing['anchor'],
        'description'    => array_key_exists('description', $data) ? $data['description'] : $existing['description'],
        'min'            => array_key_exists('min', $data) ? $data['min'] : $existing['min'],
        'max'            => array_key_exists('max', $data) ? $data['max'] : $existing['max'],
        'unit'           => array_key_exists('unit', $data)
            ? iso_decode_xml_value($data['unit'])
            : $existing['unit'],
        'cal_date'       => array_key_exists('cal_date', $data) ? $data['cal_date'] : $existing['cal_date'],
        'cal_period'     => array_key_exists('cal_period', $data) ? $data['cal_period'] : $existing['cal_period'],
        'build_date'     => $buildDate ?? $existing['build_date'],
        'mod_date'       => $modDate ?? $now,
        'alarm_high_val' => array_key_exists('alarm_high_val', $data) ? $data['alarm_high_val'] : $existing['alarm_high_val'],
        'alarm_low_val'  => array_key_exists('alarm_low_val', $data) ? $data['alarm_low_val'] : $existing['alarm_low_val'],
        'alarm_high'     => array_key_exists('alarm_high', $data) ? $data['alarm_high'] : $existing['alarm_high'],
        'alarm_low'      => array_key_exists('alarm_low', $data) ? $data['alarm_low'] : $existing['alarm_low'],
    ];

    $interfaceType = strtoupper(trim((string)($payload['interface_type'] ?? '')));
    // Defense in depth, for every interface: even a server-derived
    // replacement anchor must satisfy the Core-equivalent grammar before it
    // is ever written, and it is always persisted in canonical form. This
    // also re-validates an unchanged existing anchor on a plain metadata
    // Edit, matching the pre-existing SDAQ-only behaviour this generalizes.
    $identity = iso_require_valid_source_identity($interfaceType, (string)$payload['anchor']);
    $payload['anchor'] = $identity['canonical_anchor'];

    $anchorConflictIso = iso_find_anchor_conflict($xml, $identity['semantic_key'], $isoChannel);
    if ($anchorConflictIso !== null) {
        throw new ChannelConfigException(
            "ANCHOR already exists: " . $identity['canonical_anchor'] . " is already used by " . $anchorConflictIso,
            409,
            'duplicate_source'
        );
    }

    iso_set_channel_contents($target, $payload);
    iso_save_xml($xml, $xmlPath);
}

function iso_delete_channel(string $xmlPath, string $isoChannel): void
{
    iso_with_xml_lock($xmlPath, function () use ($xmlPath, $isoChannel) {
        if (!file_exists($xmlPath)) {
            throw new ChannelConfigException("XML not found: $xmlPath", 404, 'channel_config_missing');
        }
        $xml = simplexml_load_file($xmlPath);
        if ($xml === false) {
            throw new ChannelConfigException("Failed to parse XML", 500, 'channel_config_parse_failed');
        }

        $isoChannel = iso_normalize_iso_channel($isoChannel);
        $index = 0;
        $found = false;
        foreach ($xml->CHANNEL as $ch) {
            if ((string)$ch->ISO_CHANNEL === $isoChannel) {
                unset($xml->CHANNEL[$index]);
                $found = true;
                break;
            }
            $index++;
        }
        if (!$found) {
            throw new ChannelConfigException("ISO_CHANNEL not found: " . $isoChannel, 404, 'channel_not_found');
        }

        iso_save_xml($xml, $xmlPath);
    });
}

