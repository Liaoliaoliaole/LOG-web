(function(){
  const $  = (s, r=document) => r.querySelector(s);
  const $$ = (s, r=document) => Array.from(r.querySelectorAll(s));

  /* ========= 配置：是否使用 mock 数据 =========
     接上后端时，把 IS_MOCK 改为 false，并将 detailsData / renderLogs 替换为真实请求。 */
  const IS_MOCK = true;

  /* ========= tabs ========= */
  const panels = { details: $('#panel-details'), logs: $('#panel-logs') };
  function showTab(key){
    Object.values(panels).forEach(p => p.classList.remove('show'));
    panels[key].classList.add('show');
    if (key === 'details') renderDetails();
    if (key === 'logs')     renderLogs();
  }
  document.addEventListener('click', (e)=>{
    const t = e.target.closest('.tab[data-tab]');
    if(!t) return;
    e.preventDefault();
    $$('.tab').forEach(b => b.classList.remove('primary'));
    t.classList.add('primary');
    showTab(t.dataset.tab);
  });

  // 显示/隐藏 MOCK 标识
  $('#mockBadge').hidden = !IS_MOCK;

  /* ========= 数据（字段名沿用原库） =========
     合并了 Bus 综述字段：BUS_Utilization / Detected_SDAQs / Incomplete_SDAQs /
     Electrics.BUS_voltage / Electrics.BUS_amperage / last_calibration_UNIX
     到 connections 表中，统一以两列表展示。 */
  const detailsData = [
    {
      if_name: 'SDAQs (can0)',
      BUS_Utilization: 0.00,
      Detected_SDAQs: 0,
      Incomplete_SDAQs: 0,
      last_calibration_UNIX: 0,
      Electrics: { BUS_voltage: 24.01, BUS_amperage: 0.03 },
      connections: [
        { name:'BUS_Utilization', value: 0.00, unit:'%' },
        { name:'Detected_SDAQs', value: 0, unit:'' },
        { name:'Incomplete_SDAQs', value: 0, unit:'' },
        { name:'SDAQnet_(can0)_last_calibration_UNIX', value: 0, unit:'' },
        { name:'SDAQnet_(can0)_outVoltage', value:'24.01', unit:'V' },
        { name:'SDAQnet_(can0)_outAmperage', value:'0.03', unit:'A' },
        { name:'SDAQnet_(can0)_ShuntTemp', value:'83.0', unit:'°F' },
        // 合并 Bus Voltage/Current（来自 Electrics）
        { name:'BUS Voltage', value: 24.01, unit:'V' },
        { name:'BUS Current', value: 0.03, unit:'A' }
      ]
    },
    {
      if_name: 'SDAQs (can1)',
      BUS_Utilization: 4.70,
      Detected_SDAQs: 1,
      Incomplete_SDAQs: 0,
      last_calibration_UNIX: 0,
      Electrics: { BUS_voltage: 23.86, BUS_amperage: 0.04 },
      connections: [
        { name:'BUS_Utilization', value: 4.70, unit:'%' },
        { name:'Detected_SDAQs', value: 1, unit:'' },
        { name:'Incomplete_SDAQs', value: 0, unit:'' },
        { name:'SDAQnet_(can1)_last_calibration_UNIX', value: 0, unit:'' },
        { name:'SDAQnet_(can1)_outVoltage', value:'23.86', unit:'V' },
        { name:'SDAQnet_(can1)_outAmperage', value:'0.04', unit:'A' },
        { name:'SDAQnet_(can1)_ShuntTemp', value:'83.0', unit:'°F' },
        { name:'BUS Voltage', value: 23.86, unit:'V' },
        { name:'BUS Current', value: 0.04, unit:'A' }
      ]
    },
    {
      if_name: 'RPi_Health_Status',
      connections: [
        { name:'CPU_temp', value:'93.3', unit:'°F' },
        { name:'CPU_Util', value:'0.25', unit:'%' },
        { name:'RAM_Util', value:'1.52', unit:'%' },
        { name:'Disk_Util', value:'23.92', unit:'%' },
        { name:'Up_time', value:'0:1:20', unit:'' }
      ]
    }
  ];

  /* ========= 渲染 ========= */
  function mkDetailsTable(block){
    const tbl = document.createElement('table');
    tbl.className = 'table';

    // 头
    const thead = document.createElement('thead');
    thead.innerHTML = `<tr><th colspan="2">${block.if_name}</th></tr>`;
    tbl.appendChild(thead);

    const tb = document.createElement('tbody');

    (block.connections || []).forEach(row=>{
      const tr = document.createElement('tr');
      let label = row.name.replace('_UNIX','');
      let value;

      // 统一格式化
      if (row.name.includes('last_calibration_UNIX')) {
        value = row.value ? new Date(row.value*1000).toLocaleDateString() : 'UnCalibrated';
      } else if (typeof row.value === 'number') {
        // 数值补单位
        value = `${row.value}${row.unit||''}`;
      } else {
        value = `${row.value}${row.unit||''}`;
      }

      tr.innerHTML = `<td>${label}</td><td>${value}</td>`;
      tb.appendChild(tr);
    });

    tbl.appendChild(tb);
    return tbl;
  }

  function renderDetails(){
    const wrap = $('#detailsWrap');
    wrap.innerHTML = '';
    detailsData.forEach(b => wrap.appendChild(mkDetailsTable(b)));
  }

  function renderLogs(){
    const sel = $('#loggerSelect');
    const term = $('#logTerminal');
    if (IS_MOCK) {
      term.textContent =
`[MOCK]  Morfeas boot…
[MOCK]  webif ready
[MOCK]  sdaq0: 16 channels online
[MOCK]  can1 utilization 74%
[MOCK]  tail -f ${sel.value || 'system.log'}`;
    } else {
      // TODO: 这里替换为真实 fetch 日志数据
      term.textContent = 'Loading logs…';
    }
  }

  // 默认加载 Details
  renderDetails();
})();
