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
 * Report one violation. In fail-fast mode ($errors === null, used by every
 * single-item write path -- Add/Edit/Replace) this throws immediately with
 * the caller's own HTTP status, same as before. In collect-all mode
 * (an array is passed, used by iso_collect_document_errors() below for FTP
 * Restore's whole-document preflight) it appends and keeps going, so a
 * candidate with several unrelated problems can be fixed in one round trip
 * instead of one submit-and-retry per violation -- mirroring
 * log_config_report_error()'s same two-mode shape exactly, so the two
 * validators do not drift into different reporting behavior.
 */
function iso_report_error(?array &$errors, string $message, int $httpCode, string $code): void
{
    if ($errors === null) {
        throw new ChannelConfigException($message, $httpCode, $code);
    }
    $errors[] = ['code' => $code, 'message' => $message];
}

/*
 * Whole-document semantic gate matching Core's OPC UA configuration rules.
 * It intentionally rechecks identity grammar and duplicates across the
 * final document instead of trusting individual writer paths. Walks every
 * CHANNEL and returns every violation found, instead of stopping at the
 * first one. See iso_report_error()'s docblock for why this exists and how
 * it composes with fail-fast callers.
 */
function iso_collect_document_errors(SimpleXMLElement $xml, ?array &$errors = []): array
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

        // No element name outside the DTD's CHANNEL content model.
        foreach ($childNames as $name) {
            if (!isset($orderIndex[$name])) {
                iso_report_error(
                    $errors,
                    "CHANNEL \"$isoForError\" contains an element not in Morfeas.dtd: $name",
                    500,
                    'invalid_document_structure'
                );
            }
        }
        // The six required elements must all be present.
        foreach ($requiredElements as $name) {
            if (!in_array($name, $childNames, true)) {
                iso_report_error(
                    $errors,
                    "CHANNEL \"$isoForError\" is missing required element: $name",
                    500,
                    'invalid_document_structure'
                );
            }
        }
        // Elements that are present must appear in DTD sequence order.
        $lastIdx = -1;
        foreach ($childNames as $name) {
            if ($orderIndex[$name] < $lastIdx) {
                iso_report_error(
                    $errors,
                    "CHANNEL \"$isoForError\" has elements out of Morfeas.dtd order (at $name)",
                    500,
                    'invalid_document_structure'
                );
            }
            $lastIdx = $orderIndex[$name];
        }

        // No present element's content may be empty (applies to all
        // fifteen elements, not just the six required ones -- an optional
        // element that is present but empty is just as fatal to Core).
        foreach ($ch->children() as $child) {
            if (trim((string)$child) === '') {
                iso_report_error(
                    $errors,
                    "CHANNEL \"$isoForError\" element " . $child->getName() . " must not be empty",
                    409,
                    'empty_element'
                );
            }
        }

        /*
         * Validate identity text exactly as stored. Core compares
         * INTERFACE_TYPE with strcmp() and its strict anchor decoders do not
         * trim, so normalising here would validate a different document.
         */
        $isoChannel = (string)$ch->ISO_CHANNEL;
        $interfaceType = (string)$ch->INTERFACE_TYPE;
        $anchor = (string)$ch->ANCHOR;

        if ($isoChannel !== trim($isoChannel)) {
            iso_report_error(
                $errors,
                'ISO_CHANNEL must not contain leading or trailing whitespace',
                409,
                'invalid_iso_channel'
            );
        }

        // Check ISO_CHANNEL as stored, including the leading underscore.
        if (strlen($isoChannel) >= 20) { // ISO_channel_name_size
            iso_report_error(
                $errors,
                "ISO_CHANNEL is too long (>= 20 bytes): $isoChannel",
                409,
                'invalid_iso_channel'
            );
        }
        // ISO_CHANNEL must not contain '.'.
        if (strpos($isoChannel, '.') !== false) {
            iso_report_error(
                $errors,
                "ISO_CHANNEL contains an illegal '.': $isoChannel",
                409,
                'invalid_iso_channel'
            );
        }

        // MDAQ is intentionally absent because its channel implementation is
        // retired. An unsupported INTERFACE_TYPE stops here: whether UNIT is
        // required, what grammar ANCHOR must satisfy, and what counts as a
        // duplicate source are all rules that depend on knowing which
        // device this is. None of them can be meaningfully evaluated
        // against a type Core does not recognize, so this row contributes
        // nothing else -- not even its ISO_CHANNEL -- to the checks below.
        if (!in_array($interfaceType, $knownInterfaces, true)) {
            iso_report_error(
                $errors,
                "CHANNEL \"$isoChannel\" has an unsupported INTERFACE_TYPE: $interfaceType",
                409,
                'unsupported_interface'
            );
            continue;
        }

        // ANCHOR must satisfy the interface's strict full-string grammar.
        // A failure here does not skip the checks below: UNIT and
        // ISO_CHANNEL-uniqueness do not depend on a parsed identity, and
        // only duplicate_source (further down) genuinely needs one.
        $identity = iso_parse_source_identity($interfaceType, $anchor);
        if ($identity === null) {
            iso_report_error(
                $errors,
                "CHANNEL \"$isoChannel\" has an invalid ANCHOR for interface $interfaceType: $anchor",
                409,
                'invalid_anchor'
            );
        }

        // IOBOX/MTI/NOX must carry a non-empty XML-owned Unit; SDAQ's
        // Unit is runtime-owned and not read from XML. Independent of
        // whether the ANCHOR parsed, so it is checked regardless.
        if ($interfaceType !== 'SDAQ' && trim((string)$ch->UNIT) === '') {
            iso_report_error(
                $errors,
                "CHANNEL \"$isoChannel\" ($interfaceType) is missing a non-empty UNIT",
                409,
                'missing_required_unit'
            );
        }

        // ISO_CHANNEL must be unique across the document. Also independent
        // of ANCHOR parsing -- a duplicate name is a real, separate problem
        // even on a row whose ANCHOR is also broken.
        if (isset($seenIso[$isoChannel])) {
            iso_report_error(
                $errors,
                "ISO_CHANNEL \"$isoChannel\" appears more than once",
                409,
                'channel_conflict'
            );
        }
        $seenIso[$isoChannel] = true;

        // The parsed source identity must be unique across the document,
        // per interface (compared on decoded fields, never on raw ANCHOR
        // text -- iso_parse_source_identity()'s semantic_key already
        // encodes the interface, so cross-interface collisions cannot
        // occur here by construction). Unlike the two checks above, this
        // one genuinely cannot run without a successfully parsed identity.
        if ($identity !== null) {
            $key = $identity['semantic_key'];
            if (isset($seenSemanticKey[$key])) {
                iso_report_error(
                    $errors,
                    "ANCHOR \"$anchor\" of ISO_CHANNEL \"$isoChannel\" duplicates the source already used by ISO_CHANNEL \"{$seenSemanticKey[$key]}\"",
                    409,
                    'duplicate_source'
                );
            }
            $seenSemanticKey[$key] = $isoChannel;
        }
    }

    // DTD validation already makes the Core aggregate "no identity fields"
    // failure unreachable for a non-empty document; Core accepts an empty
    // NODESet.

    // In fail-fast mode ($errors passed as null), any violation above has
    // already thrown; reaching here means there were none, so $errors is
    // still null and this coalesces it to satisfy the return type -- the
    // fail-fast caller (iso_validate_document()) never looks at it anyway.
    return $errors ?? [];
}

/* Single-item write paths (Add/Edit/Replace): fail fast on the first violation. */
function iso_validate_document(SimpleXMLElement $xml): void
{
    $errors = null;
    iso_collect_document_errors($xml, $errors);
}

/*
 * Structural half of validating the exact bytes about to be written:
 * well-formed XML, correct root element, matching DOCTYPE, DTD validation,
 * and no stray top-level element. All-or-nothing by nature (a document that
 * is not well-formed has no CHANNELs to enumerate violations across), so
 * unlike iso_collect_document_errors() this always throws on the first
 * problem -- mirroring log_config_validate_dtd_structure()'s equivalent
 * treatment of the Morfeas_Config.xml side, called the same way from inside
 * a try/catch by callers (like ftp_backup_validate_bundle_candidates())
 * that want the semantic errors below it collected instead of thrown.
 */
function iso_validate_final_xml_structure(
    string $xmlBytes,
    ?string $dtdDir = null,
    bool $requireDtd = false
): SimpleXMLElement {
    $dom = new DOMDocument('1.0');
    libxml_use_internal_errors(true);
    try {
        $loaded = $dom->loadXML($xmlBytes, LIBXML_NONET);
        $loadErrors = libxml_get_errors();
        libxml_clear_errors();
    } finally {
        libxml_use_internal_errors(false);
    }

    if (!$loaded || $dom->documentElement === null) {
        $first = $loadErrors[0] ?? null;
        $detail = $first ? trim((string)$first->message) : 'unknown XML parse error';
        throw new ChannelConfigException(
            'Final OPC_UA_Config.xml bytes are not well-formed XML: ' . $detail,
            409,
            'invalid_document_structure'
        );
    }
    if ($dom->documentElement->nodeName !== 'NODESet') {
        throw new ChannelConfigException(
            'OPC_UA_Config.xml has the wrong root element: expected <NODESet>, got <'
                . $dom->documentElement->nodeName . '>',
            409,
            'invalid_document_structure'
        );
    }

    $hasDtd = $dom->doctype !== null;
    if ($requireDtd || $hasDtd) {
        if ($dom->doctype === null
            || $dom->doctype->name !== 'NODESet'
            || trim((string)$dom->doctype->systemId) === '') {
            throw new ChannelConfigException(
                'OPC_UA_Config.xml is missing a matching NODESet DOCTYPE declaration',
                409,
                'invalid_document_structure'
            );
        }
        $dtdPath = $dtdDir !== null ? rtrim($dtdDir, '/') . '/Morfeas.dtd' : '';
        if (!is_file($dtdPath)) {
            throw new ChannelConfigException(
                'Morfeas.dtd is unavailable for final OPC_UA_Config.xml validation',
                500,
                'channel_config_validation_unavailable'
            );
        }

        libxml_set_external_entity_loader(static function (?string $public, ?string $system, array $context) use ($dtdPath) {
            if (is_string($system) && basename($system) === 'Morfeas.dtd') {
                return fopen($dtdPath, 'r');
            }
            return null;
        });
        libxml_use_internal_errors(true);
        try {
            $valid = $dom->validate();
            $dtdErrors = libxml_get_errors();
            libxml_clear_errors();
        } finally {
            libxml_set_external_entity_loader(null);
            libxml_use_internal_errors(false);
        }
        if (!$valid) {
            $first = $dtdErrors[0] ?? null;
            $detail = $first ? trim((string)$first->message) : 'unknown DTD validation error';
            throw new ChannelConfigException(
                'Final OPC_UA_Config.xml bytes failed DTD validation: ' . $detail,
                409,
                'invalid_document_structure'
            );
        }
    }

    foreach ($dom->documentElement->childNodes as $child) {
        if ($child instanceof DOMElement && $child->nodeName !== 'CHANNEL') {
            throw new ChannelConfigException(
                'NODESet contains an element not allowed by Morfeas.dtd: ' . $child->nodeName,
                409,
                'invalid_document_structure'
            );
        }
    }

    $xml = simplexml_import_dom($dom);
    if (!$xml instanceof SimpleXMLElement) {
        throw new ChannelConfigException(
            'Final OPC_UA_Config.xml bytes could not be converted for semantic validation',
            500,
            'channel_config_parse_failed'
        );
    }
    return $xml;
}

/*
 * Full exact-bytes gate for single-item write paths (Add/Edit/Replace):
 * structure, then fail fast on the first semantic violation. FTP Restore's
 * whole-document preflight does NOT call this -- it calls
 * iso_validate_final_xml_structure() directly and then
 * iso_collect_document_errors(), so a candidate with several unrelated
 * problems is reported all at once instead of one submit-and-retry per
 * violation.
 */
function iso_validate_final_xml_bytes(
    string $xmlBytes,
    ?string $dtdDir = null,
    bool $requireDtd = false
): SimpleXMLElement {
    $xml = iso_validate_final_xml_structure($xmlBytes, $dtdDir, $requireDtd);
    iso_validate_document($xml);
    return $xml;
}

function iso_resolve_dtd_dir(string $xmlPath): ?string
{
    $coreSrcDir = getenv('MORFEAS_CORE_SRC_DIR');
    $candidates = [
        dirname($xmlPath),
        ($coreSrcDir === false || trim($coreSrcDir) === '')
            ? __DIR__ . '/../../../../LOG-core/configuration'
            : $coreSrcDir . '/configuration',
    ];
    foreach ($candidates as $candidate) {
        $resolved = realpath($candidate);
        if ($resolved !== false && is_file($resolved . '/Morfeas.dtd')) {
            return $resolved;
        }
    }
    return null;
}

function iso_save_xml(SimpleXMLElement $xml, string $xmlPath): void
{
    $dom = new DOMDocument('1.0');
    $dom->encoding = 'UTF-8';
    $dom->preserveWhiteSpace = false;
    $dom->formatOutput = true;

    if ($dom->loadXML($xml->asXML()) === false) {
        throw new ChannelConfigException('Failed to format XML output', 500, 'channel_config_format_failed');
    }

    $dtdDir = iso_resolve_dtd_dir($xmlPath);
    if ($dtdDir === null) {
        throw new ChannelConfigException(
            'Morfeas.dtd is unavailable for final OPC_UA_Config.xml validation',
            500,
            'channel_config_validation_unavailable'
        );
    }

    // Old test/config files may omit the declaration, but Core always
    // parses OPC_UA_Config.xml with XML_PARSE_DTDVALID. Canonical Web output
    // therefore restores the declaration before validating and writing.
    if ($dom->doctype === null) {
        $implementation = new DOMImplementation();
        $doctype = $implementation->createDocumentType('NODESet', '', 'Morfeas.dtd');
        $withDtd = $implementation->createDocument(null, '', $doctype);
        $withDtd->encoding = 'UTF-8';
        $withDtd->preserveWhiteSpace = false;
        $withDtd->formatOutput = true;
        $withDtd->appendChild($withDtd->importNode($dom->documentElement, true));
        $dom = $withDtd;
    }

    $xmlString = $dom->saveXML();
    if ($xmlString === false) {
        throw new ChannelConfigException('Failed to serialize XML', 500, 'channel_config_serialize_failed');
    }

    iso_validate_final_xml_bytes($xmlString, $dtdDir, true);

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
        'unit'           => trim((string)$ch->UNIT),
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

/*
 * True if $digits (a decimal string with no leading zero -- callers enforce
 * that via regex before calling this) fits in a uint32. String comparison
 * avoids float rounding on 32-bit PHP builds for very large decimal strings.
 */
function iso_digits_fit_uint32(string $digits): bool
{
    return !(strlen($digits) > 10 || (strlen($digits) === 10 && strcmp($digits, '4294967295') > 0));
}

/* Core-equivalent SDAQ grammar. Keep this aligned with decode_sdaq_anchor(). */
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

/* Core-equivalent IOBOX grammar. Keep this aligned with decode_iobox_anchor(). */
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

/* Core-equivalent MTI grammar. RMSW/MUX is runtime metadata, never an anchor. */
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

/* Core-equivalent NOX grammar; legal addr_ and addr: aliases canonicalize here. */
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

/* Every write path uses this strict, interface-aware identity parser. */
function iso_parse_source_identity(string $interfaceType, string $anchor): ?array
{
    // Do not trim: whitespace is not a valid part of an anchor identity.
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

function iso_add_text_child(SimpleXMLElement $parent, string $name, $value): void
{
    $parentNode = dom_import_simplexml($parent);
    if (!$parentNode instanceof DOMElement || $parentNode->ownerDocument === null) {
        throw new ChannelConfigException(
            'Failed to access XML document while writing ' . $name,
            500,
            'channel_config_serialize_failed'
        );
    }

    $element = $parentNode->ownerDocument->createElement($name);
    $element->appendChild($parentNode->ownerDocument->createTextNode((string)$value));
    $parentNode->appendChild($element);
}

function iso_set_channel_contents(SimpleXMLElement $ch, array $data): void
{
    foreach ($ch->xpath('*') as $child) {
        unset($child[0]);
    }

    iso_add_text_child($ch, 'ISO_CHANNEL', $data['iso_channel']);
    iso_add_text_child($ch, 'INTERFACE_TYPE', $data['interface_type']);
    iso_add_text_child($ch, 'ANCHOR', $data['anchor']);
    iso_add_text_child($ch, 'DESCRIPTION', $data['description']);
    iso_add_text_child($ch, 'MIN', $data['min']);
    iso_add_text_child($ch, 'MAX', $data['max']);

    if ($data['unit'] !== null && $data['unit'] !== '') {
        iso_add_text_child($ch, 'UNIT', $data['unit']);
    }
    if ($data['cal_date'] !== null && $data['cal_date'] !== '') {
        iso_add_text_child($ch, 'CAL_DATE', $data['cal_date']);
    }
    if ($data['cal_period'] !== null && $data['cal_period'] !== '') {
        iso_add_text_child($ch, 'CAL_PERIOD', $data['cal_period']);
    }
    if ($data['build_date'] !== null && $data['build_date'] !== '') {
        iso_add_text_child($ch, 'BUILD_DATE', $data['build_date']);
    }
    if ($data['mod_date'] !== null && $data['mod_date'] !== '') {
        iso_add_text_child($ch, 'MOD_DATE', $data['mod_date']);
    }
    if ($data['alarm_high_val'] !== null && $data['alarm_high_val'] !== '') {
        iso_add_text_child($ch, 'ALARM_HIGH_VAL', $data['alarm_high_val']);
    }
    if ($data['alarm_low_val'] !== null && $data['alarm_low_val'] !== '') {
        iso_add_text_child($ch, 'ALARM_LOW_VAL', $data['alarm_low_val']);
    }
    if ($data['alarm_high'] !== null && $data['alarm_high'] !== '') {
        iso_add_text_child($ch, 'ALARM_HIGH', $data['alarm_high']);
    }
    if ($data['alarm_low'] !== null && $data['alarm_low'] !== '') {
        iso_add_text_child($ch, 'ALARM_LOW', $data['alarm_low']);
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

/* Compatibility wrapper; grammar remains in iso_parse_sdaq_identity(). */
function iso_sdaq_anchor_is_valid(string $anchor): bool
{
    return iso_parse_sdaq_identity($anchor) !== null;
}

/* Lock-free Add body; callers resolve live candidates while holding the lock. */
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

/* Build a new payload after the caller has validated its canonical identity. */
function iso_build_new_channel_payload(string $isoChannel, array $data): array
{
    $now = time();
    $buildDate = iso_pick_value($data, ['build_date', 'build_date_unix', 'Build_date_UNIX']);
    $modDate = iso_pick_value($data, ['mod_date', 'mod_date_unix', 'Mod_date_UNIX']);
    $interfaceType = strtoupper(trim((string)($data['interface_type'] ?? '')));

    if ($interfaceType === 'SDAQ' && array_key_exists('unit', $data)) {
        throw new ChannelConfigException(
            'SDAQ Unit is supplied by live runtime metadata and must not be stored in OPC_UA_Config.xml',
            400,
            'sdaq_unit_not_allowed'
        );
    }
    if ($interfaceType === 'SDAQ' && (array_key_exists('cal_date', $data) || array_key_exists('cal_period', $data))) {
        throw new ChannelConfigException(
            'SDAQ calibration metadata is supplied by live runtime metadata and must not be stored in OPC_UA_Config.xml',
            400,
            'sdaq_calibration_metadata_not_allowed'
        );
    }

    return [
        'iso_channel'    => $isoChannel,
        'interface_type' => $interfaceType,
        'anchor'         => $data['anchor'],
        'description'    => $data['description'] ?? '',
        'min'            => $data['min'] ?? '0',
        'max'            => $data['max'] ?? '0',
        'unit'           => $interfaceType === 'SDAQ' ? null : ($data['unit'] ?? null),
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

/* Plain Edit cannot change identity or server-owned audit fields. */
const ISO_EDIT_FORBIDDEN_FIELDS = [
    'interface_type',
    'anchor',
    'iso_channel',
    'build_date',
    // Audit timestamps are server-owned; Restore has its own path.
    'mod_date',
    'mod_date_unix',
    'Mod_date_UNIX',
    'build_date_unix',
    'Build_date_UNIX',
];

const ISO_SERVER_OWNED_AUDIT_FIELDS = [
    'build_date',
    'build_date_unix',
    'Build_date_UNIX',
    'mod_date',
    'mod_date_unix',
    'Mod_date_UNIX',
];

function iso_require_server_owned_audit_fields_absent(array $data): void
{
    $offenders = [];
    foreach (ISO_SERVER_OWNED_AUDIT_FIELDS as $field) {
        if (array_key_exists($field, $data)) {
            $offenders[] = $field;
        }
    }
    if ($offenders !== []) {
        throw new ChannelConfigException(
            'Server-owned audit fields are not accepted when adding channels: ' . implode(', ', $offenders),
            400,
            'add_field_not_allowed'
        );
    }
}

/* Enforce Edit ownership server-side; browser-disabled fields are not authority. */
function iso_require_edit_field_allowlist(string $xmlPath, string $isoChannel, array $data): void
{
    $offenders = [];
    foreach (ISO_EDIT_FORBIDDEN_FIELDS as $field) {
        if (array_key_exists($field, $data)) {
            $offenders[] = $field;
        }
    }

    // SDAQ Unit and calibration provenance are runtime-owned. Browser UI
    // state is not authority: reject these fields even when a caller sends
    // an otherwise valid metadata edit directly to the API.
    $sdaqRuntimeOwnedFields = ['unit', 'cal_date', 'cal_period'];
    $hasSdaqRuntimeOwnedField = false;
    foreach ($sdaqRuntimeOwnedFields as $field) {
        if (array_key_exists($field, $data)) {
            $hasSdaqRuntimeOwnedField = true;
            break;
        }
    }
    if ($hasSdaqRuntimeOwnedField && is_file($xmlPath)) {
        $xml = simplexml_load_file($xmlPath);
        if ($xml !== false) {
            $target = iso_normalize_iso_channel($isoChannel);
            foreach ($xml->CHANNEL as $ch) {
                if ((string)$ch->ISO_CHANNEL === $target) {
                    if (strtoupper(trim((string)$ch->INTERFACE_TYPE)) === 'SDAQ') {
                        foreach ($sdaqRuntimeOwnedFields as $field) {
                            if (array_key_exists($field, $data)) {
                                $offenders[] = $field . ' (SDAQ calibration metadata is runtime-owned; Core never reads it from XML)';
                            }
                        }
                    }
                    break;
                }
            }
        }
    }

    if ($offenders !== []) {
        throw new ChannelConfigException(
            'Edit may only change metadata (description, min, max, alarms, and XML-owned unit/calibration for non-SDAQ). '
                . 'Rejected identity/read-only field(s): ' . implode(', ', $offenders)
                . '. Use Replace to move a channel to a different source.',
            400,
            'edit_field_not_allowed'
        );
    }
}

function iso_update_channel(string $xmlPath, string $isoChannel, array $data): void
{
    iso_with_xml_lock($xmlPath, function () use ($xmlPath, $isoChannel, $data) {
        iso_require_edit_field_allowlist($xmlPath, $isoChannel, $data);
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
        'unit'           => array_key_exists('unit', $data) ? $data['unit'] : $existing['unit'],
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
    $payload['interface_type'] = $interfaceType;
    if ($interfaceType === 'SDAQ') {
        // SDAQ Unit and calibration provenance are runtime-owned. This also
        // removes historical XML metadata when a SDAQ channel is replaced or
        // otherwise rewritten.
        $payload['unit'] = null;
        $payload['cal_date'] = null;
        $payload['cal_period'] = null;
    }
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
