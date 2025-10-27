(() => {
  const $ = (s, r = document) => r.querySelector(s);
  const byId = id => document.getElementById(id);

  /* ========== MOCK：Network bits & MAC（来自原页结构） ========== 
     参考 Network_config.html 的 CAN 表与 MAC 字段展示。 */
  const MOCK = {
    mac: 'E4:5F:01:A1:1C:B8',
    canBitrates: { can0: '500 kbps', can1: '500 kbps' }
  }; // :contentReference[oaicite:1]{index=1}

  // 写入初值
  byId('macText').textContent = MOCK.mac;
  byId('can0').value = MOCK.canBitrates.can0;
  byId('can1').value = MOCK.canBitrates.can1;

  // Load Last（占位：恢复到 MOCK 值）
  byId('loadLast').addEventListener('click', () => {
    byId('can0').value = MOCK.canBitrates.can0;
    byId('can1').value = MOCK.canBitrates.can1;
    toast('Loaded last configuration (mock).');
  });

  // Set（占位：仅提示，不真正提交）
  byId('apply').addEventListener('click', () => {
    const v0 = byId('can0').value;
    const v1 = byId('can1').value;
    toast(`Applied: CAN0 ${v0}, CAN1 ${v1} (mock).`);
  });

  /* ========== ISOstandard 显示 ========== 
     参考 Morfeas_System_config.html/.js 的 isoSTD_develop 思路：将
     <points><FT102> <description/> <unit/> <max/> <min/> ... </FT102> …</points>
     展为表格。找不到文件时显示空状态。 */
  const ISO_PATH = 'advanced-settings/ISOstandard.xml'; // 可换成后端新路径
  $('#isoPath').textContent = `advanced-settings/${ISO_PATH}`;

  fetch(ISO_PATH, { cache: 'no-store' })
    .then(res => res.ok ? res.text() : Promise.reject(res.status))
    .then(xml => {
      const doc = new DOMParser().parseFromString(xml, 'application/xml');
      const points = doc.querySelector('points');
      if (!points) throw new Error('No <points> node');

      const tbody = byId('isoTable').querySelector('tbody');
      tbody.innerHTML = '';
      let idx = 1;
      [...points.children].forEach(node => {
        if (node.nodeType !== 1) return;
        const tr = document.createElement('tr');
        const name = node.nodeName;
        const desc = node.querySelector('description')?.textContent ?? '-';
        const unit = node.querySelector('unit')?.textContent ?? '-';
        const max = node.querySelector('max')?.textContent ?? '-';
        const min = node.querySelector('min')?.textContent ?? '-';
        tr.innerHTML = `
          <td><b>${idx++}</b></td>
          <td>${name}</td>
          <td>${desc}</td>
          <td>${unit}</td>
          <td>${max}</td>
          <td>${min}</td>`;
        tbody.appendChild(tr);
      });
      // 若 points 为空
      if (!tbody.children.length) { byId('isoEmpty').style.display = 'block'; }
    })
    .catch(() => {
      byId('isoEmpty').style.display = 'block';
    });
  // （原库对 ISO XML 的校验/清洗逻辑在 isoSTD_xml_file_validation.js，你将来接后端可复用。） 

  /* ========== 小提示 ==========
     简单 toast，不依赖外部库，仅视觉反馈占位。 */
  function toast(text) {
    let el = document.createElement('div');
    el.textContent = text;
    Object.assign(el.style, {
      position: 'fixed', left: '50%', bottom: '24px', transform: 'translateX(-50%)',
      background: '#111827', color: '#fff', padding: '10px 14px', borderRadius: '10px',
      boxShadow: '0 10px 30px rgba(0,0,0,.2)', zIndex: 9999, fontWeight: '700'
    });
    document.body.appendChild(el);
    setTimeout(() => { el.style.opacity = '0'; el.style.transition = 'opacity .3s'; }, 1400);
    setTimeout(() => el.remove(), 1750);
  }
})();
