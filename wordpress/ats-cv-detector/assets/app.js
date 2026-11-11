(() => {
    'use strict';
    const host = document.getElementById('atscv-workspace');
    if (!host || !window.ATSCV) return;
    const config = window.ATSCV;
    const status = document.getElementById('atscv-status');
    const esc = value => String(value ?? '').replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
    let state, tab = 'dashboard', current = null, dirty = false, saving = null, saveTimer, version = 0, result = null, resultSource = '', activeOperation = null, jobDraft = '', historyPage = 1, billingPage = 1;
    const fields = ['name','role','email','phone','location','website','summary','skills','core_strengths','references','raw_text'];
    const rows = {experience:['title','company','period','location','description'],education:['degree','school','period','grade'],courses:['title','institution','period'],achievements:['title','description'],languages:['name','level'],custom_sections:['title','content']};
    const labels = {name:'Full name',role:'Professional headline',email:'Email',phone:'Phone',location:'Location',website:'Website',summary:'Professional summary',skills:'Skills',core_strengths:'Strengths and competencies',references:'References',raw_text:'Imported CV text',experience:'Professional experience',education:'Education',courses:'Certifications and courses',achievements:'Achievements',languages:'Languages',custom_sections:'Custom sections'};
    function message(text, error = false) { status.textContent = text; status.classList.toggle('atscv-error', error); }
    async function api(path, body, method = body === undefined ? 'GET' : 'POST') {
        const options = {method,credentials:'same-origin',headers:{Accept:'application/json','X-WP-Nonce':config.nonce}};
        if (body !== undefined) { if (body instanceof FormData) options.body = body; else { options.headers['Content-Type']='application/json'; options.body=JSON.stringify(body); } }
        const response = await fetch(config.api + path, options);
        let data; try { data = await response.json(); } catch { throw Error('The server returned an unreadable response. Your current edits remain in this tab.'); }
        if (!response.ok) throw Error(data.message || 'The request could not be completed.');
        return data;
    }
    const money = cents => 'USD ' + (Number(cents)/100).toFixed(2);
    const blank = () => ({id:0,revision:0,title:'My CV',template:'essential',data:{...Object.fromEntries(fields.map(k=>[k,''])),...Object.fromEntries(Object.keys(rows).map(k=>[k,[]])),section_order:[],labels:{}}});
    function cvText() {
        if (!current) return '';
        if (current.data.raw_text) return current.data.raw_text;
        return fields.filter(k=>k!=='raw_text').map(k=>current.data[k]).concat(Object.keys(rows).flatMap(k=>current.data[k].map(r=>Object.values(r).join('\n')))).filter(Boolean).join('\n\n');
    }
    const list = (items, render) => items.length ? items.map(render).join('') : '<p class="atscv-muted">Nothing here yet.</p>';
    async function refresh() { state = await api('state?page='+(tab==='history'?historyPage:tab==='billing'?billingPage:1)); }
    function download(format, reference = '') {
        const form = document.createElement('form'); form.method='POST'; form.action=config.download; form.target='_blank';
        for (const [key,value] of Object.entries({action:'atscv_download',_wpnonce:config.downloadNonce,format,id:current?.id || '',reference})) { const input=document.createElement('input'); input.type='hidden'; input.name=key; input.value=value; form.append(input); }
        document.body.append(form); form.submit(); form.remove();
    }
    async function save() {
        clearTimeout(saveTimer);
        if (saving) { await saving; if (dirty) return save(); return; }
        if (!current || !dirty) return;
        const snapshot = structuredClone(current), savedVersion = version;
        saving = (async () => {
            message('Saving your CV…');
            const saved = await api('resumes', snapshot);
            current.id = saved.id; current.revision = saved.revision;
            dirty = version !== savedVersion;
            message(dirty ? 'Saving latest edits…' : 'Saved.');
        })();
        try { await saving; } finally { saving = null; }
        if (dirty) return save();
    }
    function changed() { dirty = true; version++; clearTimeout(saveTimer); saveTimer=setTimeout(()=>save().catch(e=>message(e.message,true)),900); }
    async function navigate(next) {
        try { await save(); tab=next; await refresh(); render(); } catch(e) { message(e.message,true); }
    }
    function render() {
        document.querySelectorAll('.atscv-nav [data-tab]').forEach(button=>{ button.classList.toggle('active',button.dataset.tab===tab); button.setAttribute('aria-current',button.dataset.tab===tab?'page':'false'); });
        if (tab==='dashboard') dashboard(); else if (tab==='builder') builder(); else if (tab==='checker') checker(); else if (tab==='billing') billing(); else history();
        if(tab==='history'||tab==='billing') {
            const page=state.pagination.page, total=tab==='billing'?state.pagination.payments:Math.max(state.pagination.resumes,state.pagination.operations);
            host.insertAdjacentHTML('beforeend',`<div class="atscv-toolbar"><button data-page="${page-1}" class="secondary" ${page<=1?'disabled':''}>Previous page</button><span>Page ${page}</span><button data-page="${page+1}" class="secondary" ${page*25>=total?'disabled':''}>Next page</button></div>`);
        }
    }
    function dashboard() {
        host.innerHTML=`<section class="atscv-hero"><div><p class="atscv-kicker">Your workspace</p><h2>Your next role starts with a clear CV.</h2><p>Create, check and refine your CV using your actual skills and experience.</p><button data-go="builder">Create or edit a CV</button></div><div class="atscv-balance"><strong>${esc(state.wallet.available)}</strong><span>AI credits available</span><span>${esc(state.wallet.plan)} plan</span></div></section><div class="atscv-grid"><section class="atscv-card"><h2>Your CVs</h2>${list(state.resumes,r=>`<div class="atscv-item"><div><strong>${esc(r.title)}</strong><small>${esc(r.updated_at)} UTC</small></div><button data-open="${esc(r.id)}" class="secondary">Open</button></div>`)}<button data-new="1" class="secondary">Start a new CV</button></section><section class="atscv-card"><h2>How credits work</h2><p>Job-to-CV analysis: 5 credits<br>AI CV revamp: 10 credits<br>Manual editing and downloads: no credits</p><p>Free accounts receive 15 welcome credits once. Monthly allowances expire at the paid-period boundary. Purchased top-ups remain available.</p><button data-go="checker" class="secondary">Check and improve</button></section></div>`;
    }
    function field(key, value, area = false, attrs = '') {
        return `<label>${esc(labels[key] || key.replaceAll('_',' '))}${area?`<textarea data-field="${esc(key)}" ${attrs}>${esc(value)}</textarea>`:`<input data-field="${esc(key)}" value="${esc(value)}" ${attrs}>`}</label>`;
    }
    function builder() {
        current ||= blank(); const data=current.data;
        host.innerHTML=`<section class="atscv-card"><div class="atscv-toolbar"><h2>CV builder</h2><button data-save="1">Save CV</button><button data-preview="1" class="secondary">Preview</button><button data-export="pdf" class="secondary">Download PDF</button><button data-export="docx" class="secondary">Download Word</button></div><p>Downloads use the last saved revision. Imported CVs preserve text; clear the imported-text field to build a structured CV instead.</p><div class="atscv-grid"><label>Document name<input id="atscv-title" value="${esc(current.title)}" maxlength="150"></label><label>Template style<select id="atscv-template">${state.templates.map(t=>`<option value="${esc(t.id)}" ${t.id===current.template?'selected':''} ${!t.allowed&&t.id!==current.template?'disabled':''}>${esc(t.name)}${!t.allowed?' · Paid':''}</option>`).join('')}</select></label></div><label>Import PDF or DOCX (up to 5 MB)<input type="file" id="atscv-import" accept=".pdf,.docx"></label><details ${data.raw_text?'open':''}><summary>Imported CV text</summary>${field('raw_text',data.raw_text,true,'maxlength="60000"')}</details><div class="atscv-fields">${fields.filter(k=>k!=='raw_text').map(k=>field(k,data[k],['summary','skills','core_strengths','references'].includes(k),`maxlength="${['summary','skills','core_strengths','references'].includes(k)?5000:255}"`)).join('')}</div>${Object.entries(rows).map(([section,keys])=>`<section class="atscv-section"><div class="atscv-toolbar"><h3>${esc(labels[section])}</h3><button data-add="${section}" class="secondary">Add entry</button></div>${data[section].map((row,index)=>`<div class="atscv-entry" data-section="${section}" data-index="${index}"><div class="atscv-row-fields">${keys.map(key=>`<label>${esc(key.replaceAll('_',' '))}${['description','content'].includes(key)?`<textarea data-cell="${key}" maxlength="5000">${esc(row[key])}</textarea>`:`<input data-cell="${key}" value="${esc(row[key])}" maxlength="255">`}</label>`).join('')}</div><button data-remove="${section}:${index}" class="danger">Remove entry</button></div>`).join('')}</section>`).join('')}<details><summary>Section ordering and headings</summary><label>Section order (comma-separated keys)<input id="atscv-order" value="${esc(data.section_order.join(', '))}" placeholder="summary, experience, education, skills"></label>${Object.entries(labels).filter(([key])=>['summary','experience','education','skills','core_strengths','courses','achievements','languages','references'].includes(key)).map(([key,label])=>`<label>${esc(label)} heading<input data-label="${key}" value="${esc(data.labels[key] || '')}" maxlength="80" placeholder="${esc(label)}"></label>`).join('')}</details><div id="atscv-preview" hidden></div></section>`;
        const discard=document.createElement('button'); discard.type='button'; discard.className='secondary'; discard.dataset.discard='1'; discard.textContent='Close without saving';
        host.querySelector('.atscv-toolbar').append(discard);
    }
    function preview() {
        const panel=document.getElementById('atscv-preview'); if(!panel) return; panel.hidden=false;
        const data=current.data;
        panel.innerHTML=`<article class="atscv-paper"><h1>${esc(data.name)}</h1><p>${esc(data.role)}</p><p>${['email','phone','location','website'].map(k=>esc(data[k])).filter(Boolean).join(' · ')}</p>${data.raw_text?`<pre>${esc(data.raw_text)}</pre>`:fields.filter(k=>['summary','skills','core_strengths'].includes(k)&&data[k]).map(k=>`<h2>${esc(data.labels[k]||labels[k])}</h2><p>${esc(data[k]).replaceAll('\n','<br>')}</p>`).join('')+Object.keys(rows).filter(k=>data[k].length).map(k=>`<h2>${esc(data.labels[k]||labels[k])}</h2>${data[k].map(row=>`<p>${Object.values(row).filter(Boolean).map(esc).join('<br>')}</p>`).join('')}`).join('')+(data.references?`<h2>References</h2><p>${esc(data.references).replaceAll('\n','<br>')}</p>`:'')}</article>`;
        panel.scrollIntoView({behavior:'smooth',block:'start'});
    }
    function checker() {
        host.innerHTML=`<section class="atscv-card"><h2>Check and improve your CV</h2><p>${current?`Using your current CV: <strong>${esc(current.title)}</strong>.`:'Open or create a CV in the builder first.'}</p><button data-go="builder" class="secondary">Open builder</button><label>Job advertisement<textarea id="atscv-job" maxlength="25000" rows="9" placeholder="Paste the actual job advertisement…">${esc(jobDraft)}</textarea></label><label class="atscv-consent"><input id="atscv-ai-consent" type="checkbox"> I consent to sending this CV and job advertisement to the configured AI provider for this request. I will review factual accuracy before applying changes.</label><div class="atscv-toolbar"><button data-basic="1" class="secondary" ${!current?'disabled':''}>Basic check · Free</button><button data-ai="analyse" ${!current||!state.ai_ready?'disabled':''}>Analyse job match · 5 credits</button><button data-ai="revamp" ${!current||!state.ai_ready?'disabled':''}>Revamp CV · 10 credits</button></div>${!state.ai_ready?'<p>AI processing has not been configured by the site administrator. No credits will be charged.</p>':''}<div id="atscv-results"></div></section>`;
        if(result) renderResult();
    }
    function renderResult() {
        const panel=document.getElementById('atscv-results'); if(!panel||!result) return;
        if(result.checks) { panel.innerHTML=`<h3>Basic text and structure checks</h3><p>${esc(result.summary)}</p>${result.checks.map(c=>`<p><strong>${c.passed?'Pass':'Review'}: ${esc(c.label)}</strong><br>${esc(c.advice)}</p>`).join('')}<p>${esc(result.scope)}</p>`; return; }
        panel.innerHTML=`<h3>Assessment ${Number.isFinite(result.score)?`· ${esc(result.score)}%`:''}</h3><p>${esc(result.summary)}</p><p>${esc(result.scope)}</p><div class="atscv-table"><table><thead><tr><th>Requirement</th><th>Finding</th><th>CV evidence</th></tr></thead><tbody>${(result.requirements||[]).map(r=>`<tr><td>${esc(r.requirement)}<small>${esc(r.category)} · ${esc(r.importance)}</small></td><td>${esc(r.status)}</td><td>${esc(r.cv_evidence||'Not evidenced')}</td></tr>`).join('')}</tbody></table></div><h3>Recommendations</h3><ul>${(result.recommendations||[]).map(r=>`<li>${esc(r)}</li>`).join('')}</ul><h3>Questions to confirm</h3><ul>${(result.questions||[]).map(r=>`<li>${esc(r)}</li>`).join('')}</ul>${result.revised_cv?`<h3>Review proposed changes</h3><ul>${result.changes.map(c=>`<li>${esc(c)}</li>`).join('')}</ul><div class="atscv-grid"><div><h4>Original</h4><pre>${esc(result.original_cv.raw_text || JSON.stringify(result.original_cv,null,2))}</pre></div><div><h4>Proposed</h4><pre>${esc(result.revised_cv.raw_text || JSON.stringify(result.revised_cv,null,2))}</pre></div></div><p>Check all facts before applying. Changes have not replaced your CV.</p><button data-apply="1">Apply reviewed changes</button>`:''}`;
    }
    async function pollOperation(id) {
        for(let attempt=0;attempt<100;attempt++) {
            const op=await api('operations/'+id);
            if(op.state==='completed') { result=op.result; activeOperation=null; await refresh(); message('Your result is ready.'); if(tab==='checker') renderResult(); return; }
            if(op.state==='failed') { activeOperation=null; await refresh(); throw Error('The AI operation could not be completed. Eligible reserved credits were restored. You can try a new request.'); }
            message('Processing your CV. Your credits are reserved once. You can return to this result in History.');
            await new Promise(resolve=>setTimeout(resolve,3000));
        }
        activeOperation=null; message('Processing is taking longer. Check History later; do not submit another request for the same result.');
    }
    function billing() {
        const wallet=state.wallet;
        host.innerHTML=`<section class="atscv-card"><h2>Plans and credits</h2><p><strong>${esc(wallet.available)} credits available</strong> · ${esc(wallet.allowance)} allowance · ${esc(wallet.topup)} purchased · ${esc(wallet.debt)} reversal debt</p><p>Current plan: ${esc(wallet.plan)}${wallet.period?` · Paid until ${esc(wallet.period.ends_at)} UTC`:''}</p><p>Paynow mode: <strong>${esc(state.paynow_mode)}</strong>. Test payments do not activate live subscriptions or allocate paid credits. Payments are one-time; renew manually.</p><div class="atscv-plans">${Object.entries(state.plans).map(([slug,plan])=>`<article class="atscv-plan"><h3>${esc(plan.name)}</h3><strong>${money(plan.cents)}</strong><p>${esc(plan.credits)} ${slug==='free'?'welcome credits once':slug==='annual'?'credits for the paid year':'credits per paid month'}</p><p>${slug==='annual'?'USD 47.40 charged yearly; USD 3.95/month equivalent.':slug==='free'?'One saved CV and selected templates.':'All template styles and unlimited saved CVs.'}</p>${slug!=='free'?`<button data-buy="${slug}" ${!state.paynow_ready?'disabled':''}>Choose ${esc(plan.name)}</button>`:''}</article>`).join('')}</div><label class="atscv-consent"><input id="atscv-billing-consent" type="checkbox"> I accept the site's payment terms and refund policy. This is a one-time purchase. Renewals or plan changes start after existing paid time, with credits available when that new period begins.</label><p>Need more AI credits without changing your paid period?</p><button data-topup="1" class="secondary" ${!state.paynow_ready?'disabled':''}>Buy 150 credits · USD 4.75</button><div id="atscv-payment-result"></div></section><section class="atscv-card"><h2>Paid periods</h2>${list(state.periods,p=>`<div class="atscv-item"><div><strong>${esc(p.plan)} · ${esc(p.state)}</strong><small>${esc(p.starts_at)} to ${esc(p.ends_at)} UTC</small></div>${p.state==='active'&&!p.cancelled_at?`<button data-cancel="${p.id}" class="secondary">Cancel renewal reminders</button>`:''}</div>`)}</section><section class="atscv-card"><h2>Payment history</h2><div class="atscv-table"><table><thead><tr><th>Purchase</th><th>Amount</th><th>Status</th><th>Actions</th></tr></thead><tbody>${state.payments.map(p=>`<tr><td>${esc(p.plan)} · ${esc(p.kind)}<small>${esc(p.reference)}</small></td><td>${money(p.amount_cents)}<small>${esc(p.mode)}</small></td><td>${esc(p.state)}<small>${esc(p.provider_status)}</small></td><td><button data-check="${esc(p.reference)}" class="secondary">Check status</button>${p.paid_at?`<button data-receipt="${esc(p.reference)}" class="secondary">Receipt</button>`:''}</td></tr>`).join('')}</tbody></table></div></section>`;
    }
    async function checkPayment(reference) {
        message('Confirming payment with Paynow…');
        const payment=await api('payments/'+encodeURIComponent(reference)+'/check',{}); await refresh();
        if(tab!=='billing') { tab='billing'; render(); }
        const panel=document.getElementById('atscv-payment-result');
        const messages={pending:'Payment is pending. A timeout or return to this page is not proof of payment. Continue checkout or check later.',paid:'Payment verified. Your purchased credits or paid period are recorded.',test_paid:'Test payment verified. Your live access and credits are unchanged.',cancelled:'Payment cancelled. You can choose a plan and start a fresh attempt.',failed:'Checkout could not be completed. You can retry.',reversed:'Paynow reports a refund or dispute. Associated benefits have been reversed according to policy.'};
        panel.innerHTML=`<h3>${esc(payment.state.replaceAll('_',' '))}</h3><p>${esc(messages[payment.state]||'Payment is awaiting review.')}</p><p>${esc(payment.plan)} · ${money(payment.amount_cents)} · Reference ${esc(reference)}</p>${payment.state==='pending'&&payment.checkout_url?`<a class="atscv-button" href="${esc(payment.checkout_url)}">Continue to Paynow</a>`:''}`;
        message(messages[payment.state]||'Payment status checked.');
    }
    function history() {
        host.innerHTML=`<section class="atscv-card"><h2>Saved CVs</h2>${list(state.resumes,r=>`<div class="atscv-item"><div><strong>${esc(r.title)}</strong><small>${esc(r.updated_at)} UTC</small></div><button data-open="${r.id}" class="secondary">Open</button><button data-delete="${r.id}" class="danger">Delete</button></div>`)}</section><section class="atscv-card"><h2>Analysis and revamp history</h2>${list(state.operations,o=>`<div class="atscv-item"><div><strong>${esc(o.kind)} · ${esc(o.state)}</strong><small>${esc(o.created_at)} UTC</small></div><button data-result="${o.id}" class="secondary">View result</button></div>`)}</section>`;
        host.insertAdjacentHTML('beforeend','<section class="atscv-card"><details><summary>Delete my account</summary><p>This permanently removes your account, CVs and AI content. Required financial records are retained under the site retention policy. This action does not automatically refund purchases.</p><label>Current password<input id="atscv-delete-password" type="password" autocomplete="current-password"></label><label>Type DELETE to confirm<input id="atscv-delete-confirm" autocomplete="off"></label><button data-delete-account="1" class="danger">Permanently delete my account</button></details></section>');
    }
    host.addEventListener('input',event=>{
        const target=event.target;
        if(target.id==='atscv-job') { jobDraft=target.value; return; }
        if(!current) return;
        if(target.dataset.field) { current.data[target.dataset.field]=target.value; changed(); }
        else if(target.dataset.cell) { const entry=target.closest('[data-section]'); current.data[entry.dataset.section][Number(entry.dataset.index)][target.dataset.cell]=target.value; changed(); }
        else if(target.dataset.label) { current.data.labels[target.dataset.label]=target.value; changed(); }
        else if(target.id==='atscv-title') { current.title=target.value; changed(); }
        else if(target.id==='atscv-order') { current.data.section_order=target.value.split(',').map(v=>v.trim()).filter(Boolean); changed(); }
    });
    host.addEventListener('change',async event=>{
        try {
            if(event.target.id==='atscv-template') { current.template=event.target.value; changed(); }
            if(event.target.id==='atscv-import'&&event.target.files.length) {
                const form=new FormData(); form.append('file',event.target.files[0]); message('Reading document…');
                const imported=await api('import',form); current ||= blank(); current.data=imported.data; changed(); builder(); message(imported.message);
            }
        } catch(e) { message(e.message,true); }
    });
    document.querySelector('.atscv-nav')?.addEventListener('click',event=>{ if(event.target.dataset.tab) navigate(event.target.dataset.tab); });
    host.addEventListener('click',async event=>{
        const button=event.target.closest('button'); if(!button) return;
        try {
            if(button.dataset.deleteAccount) {
                const response=await api('account/delete',{password:document.getElementById('atscv-delete-password').value,confirmation:document.getElementById('atscv-delete-confirm').value});
                dirty=false; window.location.assign(response.redirect_url); return;
            }
            if(button.dataset.page) { if(tab==='billing')billingPage=Number(button.dataset.page);else historyPage=Number(button.dataset.page); await refresh(); render(); return; }
            if(button.dataset.discard) {
                if(dirty&&!confirm('Discard unsaved edits? Your last saved revision will remain available.')) return;
                clearTimeout(saveTimer); if(saving) { try { await saving; } catch {} }
                dirty=false; current=null; tab='dashboard'; await refresh(); render(); return;
            }
            if(button.dataset.go) return await navigate(button.dataset.go);
            if(button.dataset.new) { await save(); current=blank(); dirty=false; tab='builder'; return render(); }
            if(button.dataset.open) { await save(); current=await api('resumes/'+button.dataset.open); dirty=false; tab='builder'; return render(); }
            if(button.dataset.save) { await save(); return message('Saved.'); }
            if(button.dataset.preview) return preview();
            if(button.dataset.export) { await save(); if(!current.id) throw Error('Save your CV first.'); return download(button.dataset.export); }
            if(button.dataset.add) { current.data[button.dataset.add].push(Object.fromEntries(rows[button.dataset.add].map(k=>[k,'']))); changed(); return builder(); }
            if(button.dataset.remove) { const [section,index]=button.dataset.remove.split(':'); current.data[section].splice(Number(index),1); changed(); return builder(); }
            if(button.dataset.delete) { if(!confirm('Delete this saved CV? This cannot be undone.')) return; await api('resumes/'+button.dataset.delete+'/delete',{}); if(current?.id===Number(button.dataset.delete)) current=null; await refresh(); return render(); }
            if(button.dataset.basic) { result=await api('basic-check',{text:cvText()}); return renderResult(); }
            if(button.dataset.ai) {
                if(activeOperation) throw Error('A request is already processing. Check History for its result.');
                if(!document.getElementById('atscv-ai-consent').checked) throw Error('Consent to AI processing first.');
                await save(); const kind=button.dataset.ai, job=document.getElementById('atscv-job').value;
                const key=window.crypto?.randomUUID ? crypto.randomUUID() : 'op_'+Date.now()+'_'+Math.random().toString(36).slice(2);
                resultSource=JSON.stringify(current.data);
                const op=await api('operations',{operation_key:key,kind,cv:current.data,job,consent:true}); activeOperation=op.id;
                pollOperation(op.id).catch(e=>message(e.message,true)); return;
            }
            if(button.dataset.apply) {
                if(!current) current=blank();
                if(resultSource&&JSON.stringify(current.data)!==resultSource) throw Error('The CV changed after this request. Review the current document before replacing it.');
                if(!confirm('Apply this reviewed proposal to your current CV? Confirm that the facts are correct.')) return;
                current.data=structuredClone(result.revised_cv); changed(); await save(); tab='builder'; return render();
            }
            if(button.dataset.result) { const op=await api('operations/'+button.dataset.result); if(op.state!=='completed') throw Error(op.state==='failed'?'This request failed; eligible credits were restored.':'This request is still processing.'); result=op.result; resultSource=result.original_cv?JSON.stringify(result.original_cv):''; tab='checker'; return render(); }
            if(button.dataset.buy||button.dataset.topup) {
                if(!document.getElementById('atscv-billing-consent').checked) throw Error('Accept the payment terms first.');
                button.disabled=true;
                const payment=await api('checkout',{plan:button.dataset.buy||'monthly',kind:button.dataset.topup?'topup':'subscription',consent:true});
                if(payment.redirect_url) { window.location.assign(payment.redirect_url); return; }
                message(payment.message||'Check your existing payment status before paying again.'); return await checkPayment(payment.reference);
            }
            if(button.dataset.check) return await checkPayment(button.dataset.check);
            if(button.dataset.receipt) return download('receipt',button.dataset.receipt);
            if(button.dataset.cancel) { const response=await api('periods/'+button.dataset.cancel+'/cancel-reminders',{}); await refresh(); render(); return message(response.message); }
        } catch(e) { message(e.message,true); }
        finally { if(button.isConnected) button.disabled=false; }
    });
    window.addEventListener('beforeunload',event=>{ if(dirty||saving) { event.preventDefault(); event.returnValue=''; } });
    refresh().then(async()=>{ render(); message(''); if(config.payment) { tab='billing'; render(); await checkPayment(config.payment); } }).catch(e=>message(e.message,true));
})();
