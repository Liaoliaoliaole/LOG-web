(function () {
    const $ = (id) => document.getElementById(id);

    const els = {
        status: $('status'),
        type: $('type'),
        path: $('path'),
        btnSearch: $('btnSearch'),
        iso: $('iso'),
        postfix: $('postfix'),
        desc: $('desc'),
        rangeLabel: $('rangeLabel'),
        rangeRow: $('rangeRow'),
        range: $('range'),
        min: $('min'),
        max: $('max'),
        alarmLowVal: $('alarmLowVal'),
        alarmHighVal: $('alarmHighVal'),
        alarmLow: $('alarmLow'),
        alarmHigh: $('alarmHigh'),
        unit: $('unit'),
        btnSave: $('btnSave'),
        btnCancel: $('btnCancel')
    };

    // postfix：N/A + 1..20
    (function buildPostfix() {
        const frag = document.createDocumentFragment();
        const opt0 = document.createElement('option');
        opt0.value = 'N/A'; opt0.textContent = 'N/A';
        frag.appendChild(opt0);
        for (let i = 1; i <= 20; i++) {
            const o = document.createElement('option');
            o.value = String(i); o.textContent = String(i);
            frag.appendChild(o);
        }
        els.postfix.appendChild(frag);
        els.postfix.value = 'N/A';
    })();

    /** 批量禁用：用于 SDAQ 且 range>=2 的情况 */
    function setDisabledBulk(disabled) {
        [els.min, els.max, els.alarmLowVal, els.alarmHighVal, els.unit,
        els.alarmLow, els.alarmHigh].forEach(n => n.disabled = disabled);

        // 仅用于视觉提示的类，不影响逻辑
        [els.min, els.max, els.alarmLowVal, els.alarmHighVal, els.unit]
            .forEach(n => n.classList.toggle('disabled', disabled));
    }

    /** 在“非批量”情况下，根据 Enable 勾选状态，设置 alarm 值输入框的禁用与否 */
    function applyAlarmEnableState(isBulk) {
        if (isBulk) return; // 批量时已统一禁用
        els.alarmLowVal.disabled = !els.alarmLow.checked;
        els.alarmHighVal.disabled = !els.alarmHigh.checked;
        // 仅作为视觉弱化
        els.alarmLowVal.classList.toggle('disabled', !els.alarmLow.checked);
        els.alarmHighVal.classList.toggle('disabled', !els.alarmHigh.checked);
    }

    function showRangeRow(show) {
        els.rangeLabel.classList.toggle('hidden', !show);
        els.rangeRow.classList.toggle('hidden', !show);
    }

    function syncRangeState() {
        const t = els.type.value;

        let isBulk = false;
        if (t === 'SDAQ') {
            showRangeRow(true);
            const r = Math.max(1, Number(els.range.value) || 1);
            els.range.value = r;
            isBulk = r >= 2;
            setDisabledBulk(isBulk);          // 批量 => 全部锁死
        } else {
            els.range.value = 1;
            showRangeRow(false);              // 非 SDAQ 隐藏 Range 行
            setDisabledBulk(false);
        }

        // 仅 MDAQ 且单通道时允许编辑 Unit；其它情况禁用（保持与之前一致）
        if (t === 'MDAQ' && Number(els.range.value) === 1) {
            els.unit.disabled = false; els.unit.classList.remove('disabled');
        } else if (!isBulk) { // 非批量时才由类型控制 Unit
            els.unit.disabled = true; els.unit.classList.add('disabled');
        }

        // 非批量场景下，按 Enable 同步 alarm 值输入可编辑状态（关键修正）
        applyAlarmEnableState(isBulk);
    }

    function updateStatus(msg, tone = 'normal') {
        els.status.textContent = msg;
        els.status.style.color =
            tone === 'error' ? '#e11d48' : tone === 'ok' ? '#065f46' : 'inherit';
    }

    function validate() {
        const t = els.type.value;
        const path = els.path.value.trim();
        const iso = els.iso.value.trim().replace(/[^\w-]/g, '');
        els.iso.value = iso;

        if (!t) { updateStatus('Select Type'); return false; }
        if (!path) { updateStatus('Define sensor properties'); return false; }
        if (!iso) { updateStatus('Define ISO Code'); return false; }
        if (t !== 'SDAQ' && Number(els.range.value) !== 1) {
            updateStatus('Range is only available for SDAQ', 'error'); return false;
        }
        updateStatus('Ready', 'ok');
        return true;
    }

    // 事件
    els.type.addEventListener('change', () => { syncRangeState(); validate(); });
    els.range.addEventListener('input', () => { syncRangeState(); validate(); });

    [els.path, els.iso, els.desc, els.min, els.max]
        .forEach(n => n.addEventListener('input', validate));

    // Enable 勾选时，实时应用可编辑状态
    els.alarmLow.addEventListener('change', () => {
        const t = els.type.value;
        const isBulk = (t === 'SDAQ' && Number(els.range.value) >= 2);
        applyAlarmEnableState(isBulk);
        validate();
    });
    els.alarmHigh.addEventListener('change', () => {
        const t = els.type.value;
        const isBulk = (t === 'SDAQ' && Number(els.range.value) >= 2);
        applyAlarmEnableState(isBulk);
        validate();
    });

    els.btnSearch.addEventListener('click', () => {
        alert('Device search dialog (placeholder)');
    });

    els.btnCancel.addEventListener('click', () => window.close());

    els.btnSave.addEventListener('click', () => {
        if (!validate()) return;

        const data = {
            type: els.type.value,
            path: els.path.value.trim(),
            iso: els.iso.value.trim(),
            postfix: els.postfix.value,
            desc: els.desc.value.trim(),
            range: Math.max(1, Number(els.range.value) || 1),
            min: els.min.value,
            max: els.max.value,
            alarmLowVal: els.alarmLowVal.value,
            alarmHighVal: els.alarmHighVal.value,
            alarmLow: els.alarmLow.checked ? 'yes' : 'no',
            alarmHigh: els.alarmHigh.checked ? 'yes' : 'no',
            unit: els.unit.value
        };

        if (data.type === 'SDAQ' && data.range >= 2) {
            alert(`Mock: add ${data.range} SDAQ channels from "${data.path}"\nISO: ${data.iso}${data.postfix === 'N/A' ? '' : '_' + data.postfix}`);
        } else {
            alert(`Mock: add 1 ${data.type} channel\nISO: ${data.iso}${data.postfix === 'N/A' ? '' : '_' + data.postfix}`);
        }
        window.close();
    });

    // init：关键——首次就正确灰掉 Alarm 值输入
    syncRangeState();
    validate();
})();
