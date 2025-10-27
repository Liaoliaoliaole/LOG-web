(function () {
    const $  = (s, r=document) => r.querySelector(s);
    const $$ = (s, r=document) => Array.from(r.querySelectorAll(s));
  
    // 表格
    const devBody    = $('#devBody');
    const mchk       = $('#mchk');
    const refreshBtn = $('#refreshBtn');
    const removeBtn  = $('#removeBtn');
  
    // 表单
    const propCard = $('#propCard');
    const addBtn   = $('#addBtn');
    const cancelBtn= $('#cancelBtn');
    const saveBtn  = $('#saveBtn');
  
    const devType  = $('#devType');
    const canIf    = $('#canIf');
    const devName  = $('#devName');
    const devIp    = $('#devIp');
  
    // 内存中的设备（用于渲染演示）
    let devices = [];
  
    function render() {
      devBody.innerHTML = '';
      devices.forEach((d, i) => {
        const tr = document.createElement('tr');
        tr.className = 'row';
        tr.dataset.index = i;
        tr.innerHTML = `
          <td><input type="checkbox" /></td>
          <td>${i + 1}</td>
          <td>${d.bus || '-'}</td>
          <td>${d.type}</td>
          <td>${d.ip || '-'}</td>
          <td><span class="dot st-${d.status || 'Okay'}"></span>${d.status || 'Okay'}</td>
        `;
        devBody.appendChild(tr);
      });
      syncState();
    }
  
    function syncState() {
      const rows = $$('#devBody tr');
      rows.forEach(r => r.classList.toggle('selected', r.querySelector('input[type="checkbox"]').checked));
      const checked = rows.filter(r => r.querySelector('input[type="checkbox"]').checked).length;
      removeBtn.disabled = checked === 0;
      const total = rows.length;
      mchk.checked = total > 0 && checked === total;
      mchk.indeterminate = checked > 0 && checked < total;
    }
  
    // 工具：表单可用状态切换
    function setDisabled(el, disabled) {
      el.disabled = disabled;
      el.style.background = disabled ? 'var(--bg-weak)' : '';
    }
  
    // 根据类型灰置字段
    function applyTypeRules() {
      const t = devType.value;
      const sdaqLike = (t === 'SDAQ' || t === 'NOX');
  
      // SDAQ / NOX: 灰置 Name/IP；其它: 灰置 CAN
      setDisabled(devName, sdaqLike);
      setDisabled(devIp,   sdaqLike);
      setDisabled(canIf,   !sdaqLike);
  
      if (sdaqLike) {
        devName.value = '';
        devIp.value   = '';
      }
    }
  
    // 事件：类型变化时应用规则
    devType.addEventListener('change', applyTypeRules);
  
    // 点击 Add… 展开表单
    addBtn.addEventListener('click', () => {
      propCard.style.display = 'block';
      // 重置默认值
      devType.value = 'SDAQ';
      canIf.value   = 'can0';
      devName.value = '';
      devIp.value   = '';
      applyTypeRules();
      devType.focus();
      // 滚动到表单
      propCard.scrollIntoView({ behavior: 'smooth', block: 'start' });
    });
  
    // Save → 追加到上表
    saveBtn.addEventListener('click', () => {
      const t = devType.value;
      const sdaqLike = (t === 'SDAQ' || t === 'NOX');
  
      // 校验
      if (!sdaqLike) {
        if (!devName.value.trim()) {
          alert('Please fill Device Name.');
          devName.focus(); return;
        }
        const ip = devIp.value.trim();
        if (!/^\d{1,3}(\.\d{1,3}){3}$/.test(ip)) {
          alert('Please fill a valid IPv4 Address.');
          devIp.focus(); return;
        }
      }
  
      // 组装对象
      const item = {
        type: t,
        bus : sdaqLike ? canIf.value : '-',   // 非 SDAQ/NOX，CAN 灰置不用
        ip  : sdaqLike ? '' : devIp.value.trim(),
        status: 'Okay'
      };
      devices.push(item);
      render();
  
      // 收起表单 & 清空
      propCard.style.display = 'none';
      devName.value = '';
      devIp.value = '';
    });
  
    // Cancel：仅收起
    cancelBtn.addEventListener('click', () => {
      propCard.style.display = 'none';
    });
  
    // Remove：移除选中
    removeBtn.addEventListener('click', () => {
      const rows = $$('#devBody tr').filter(r => r.querySelector('input[type="checkbox"]').checked);
      const idxs = rows.map(r => parseInt(r.dataset.index, 10)).sort((a,b)=>b-a);
      idxs.forEach(i => devices.splice(i, 1));
      render();
    });
  
    // Master checkbox
    mchk.addEventListener('change', () => {
      $$('#devBody input[type="checkbox"]').forEach(cb => cb.checked = mchk.checked);
      syncState();
    });
  
    // 行内点击选择
    devBody.addEventListener('click', (e) => {
      const tr = e.target.closest('tr');
      if (!tr) return;
      if (!e.target.closest('input[type="checkbox"]')) {
        const cb = tr.querySelector('input[type="checkbox"]');
        cb.checked = !cb.checked;
      }
      syncState();
    });
  
    // Refresh（占位：这里只清空再重渲染）
    refreshBtn.addEventListener('click', () => {
      render();
    });
  
    // 初始渲染（空表）
    render();
  })();
  