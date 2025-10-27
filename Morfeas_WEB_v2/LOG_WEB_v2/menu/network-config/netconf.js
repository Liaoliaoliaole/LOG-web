/* Network Configuration – presets + simple UI (static prototype) */
(function () {
    const $  = (s, r = document) => r.querySelector(s);
    const $$ = (s, r = document) => Array.from(r.querySelectorAll(s));
  
    /* DHCP/Static 切换：DHCP 时禁用 IP/GW/DNS */
    const modeSel = $('#modeSel');
    const editable = [ $$('.ip'), $$('.gw'), $$('.dns'), [$('#mask')] ].flat();
    function setStaticEnabled(on){
      editable.forEach((el)=>{ el.disabled = !on; el.style.background = on ? '' : 'var(--bg-weak)'; });
    }
    modeSel.addEventListener('change', ()=> setStaticEnabled(modeSel.value==='Static'));
    setStaticEnabled(true);
  
    /* 预设（示例数据，按需替换） */
    const presets = {
      LOG1 : { host:'LOG1' , ip:[192,168, 1,10], mask:24, gw:[192,168, 1,1], dns:[8,8,8,8] },
      LOG2 : { host:'LOG2' , ip:[192,168, 2,10], mask:24, gw:[192,168, 2,1], dns:[1,1,1,1] },
      LOG3 : { host:'LOG3' , ip:[192,168, 3,10], mask:24, gw:[192,168, 3,1], dns:[8,8,4,4] },
      LOG4 : { host:'LOG4' , ip:[ 10,  0, 4,10], mask:24, gw:[ 10,  0, 4,1], dns:[9,9,9,9] },
      LOG5 : { host:'LOG5' , ip:[ 10,  0, 5,10], mask:24, gw:[ 10,  0, 5,1], dns:[8,8,8,8] },
      LOG6 : { host:'LOG6' , ip:[ 10,  0, 6,10], mask:24, gw:[ 10,  0, 6,1], dns:[1,1,1,1] },
      LOG7 : { host:'LOG7' , ip:[172, 16, 7,10], mask:24, gw:[172, 16, 7,1], dns:[8,8,4,4] },
      LOG8 : { host:'LOG8' , ip:[172, 16, 8,10], mask:24, gw:[172, 16, 8,1], dns:[9,9,9,9] },
      LOG9 : { host:'LOG9' , ip:[192,168, 9,10], mask:24, gw:[192,168, 9,1], dns:[1,0,0,1] },
      LOG10: { host:'LOG10', ip:[192,168,10,10], mask:24, gw:[192,168,10,1], dns:[8,8,8,8] }
    };
  
    function fill(nodes, arr){
      for(let i=0;i<nodes.length && i<arr.length;i++) nodes[i].value = arr[i];
    }
  
    function setActivePreset(key){
      $$('[data-preset]').forEach(b => b.classList.toggle('active', b.dataset.preset === key));
    }
  
    function applyPreset(p, key){
      modeSel.value = 'Static';
      setStaticEnabled(true);
      $('#host').value = p.host;
      $('#mask').value = p.mask;
      fill($$('.ip'),  p.ip);
      fill($$('.gw'),  p.gw);
      fill($$('.dns'), p.dns);
      setActivePreset(key);
    }
  
    // 绑定按钮
    $$('[data-preset]').forEach(btn=>{
      btn.addEventListener('click', ()=>{
        const key = btn.dataset.preset;
        if (presets[key]) applyPreset(presets[key], key);
      });
    });
  
    /* Load / Set / Close（占位） */
    $('#loadLastBtn').addEventListener('click', ()=> alert('Load Last (placeholder).'));
    $('#commitBtn').addEventListener('click', ()=> alert('Set (placeholder). Values are not submitted in the static prototype.'));
    $('#closeBtn').addEventListener('click', ()=> window.close());
  })();
  