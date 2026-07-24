        </div><!-- .content -->
    </div><!-- .app-container -->
</main>

<?php if (isLoggedIn()): ?>
<nav class="app-bottom">
    <div class="app-bottom-inner">
        <a class="app-tab <?= $navOn('dashboard') ?>" href="dashboard.php"><i data-lucide="layout-grid"></i>Dashboard</a>
        <a class="app-tab <?= $navOn('quote_add') ?>" href="quote_add.php"><i data-lucide="calculator"></i>Builder</a>
        <a class="app-tab <?= $navOn(['quotes','quote_view','quote_edit']) ?>" href="quotes.php"><i data-lucide="file-text"></i>Quotes</a>
        <a class="app-tab <?= $navOn(['customers','customer_view','customer_add','customer_edit']) ?>" href="customers.php"><i data-lucide="users"></i>Customers</a>
        <button type="button" class="app-tab <?= $moreOn ?>" onclick="alephMore(true)"><i data-lucide="menu"></i>More</button>
    </div>
</nav>

<div class="app-sheet-bd" id="alephBd" onclick="alephMore(false)"></div>
<div class="app-sheet" id="alephSheet">
    <div class="grip"></div>
    <?php if (currentUserRole() === 'admin'): ?>
    <div class="lbl">Catalog &amp; Pricing</div>
    <a href="pq_products.php"><i data-lucide="layout-grid"></i> Product Presets</a>
    <a href="pq_papers.php"><i data-lucide="file"></i> Paper Stocks</a>
    <a href="pq_finishing.php"><i data-lucide="sparkles"></i> Finishing</a>
    <a href="pq_sizes.php"><i data-lucide="ruler"></i> Sizes</a>
    <a href="pq_engine_settings.php"><i data-lucide="sliders"></i> Engine Settings</a>
    <div class="sep"></div>
    <div class="lbl">Administration</div>
    <a href="users.php"><i data-lucide="user-cog"></i> Users</a>
    <a href="settings.php"><i data-lucide="settings"></i> Settings</a>
    <?php endif; ?>
    <a href="change_password.php"><i data-lucide="key"></i> Change Password</a>
    <div class="sep"></div>
    <a href="logout.php" class="danger"><i data-lucide="log-out"></i> Log out</a>
</div>
<?php endif; ?>

<!-- Reusable confirm dialog + toast host (used by some pages) -->
<div class="modal-overlay confirm-modal" id="confirmModal">
    <div class="modal" style="max-width:440px">
        <div class="modal-body">
            <div class="confirm-icon danger" id="confirmIcon"><i data-lucide="alert-triangle"></i></div>
            <div class="confirm-text"><h4 id="confirmTitle">Are you sure?</h4><p id="confirmDesc">This action cannot be undone.</p></div>
        </div>
        <div class="modal-footer">
            <button class="btn btn-secondary" onclick="closeConfirm()">Cancel</button>
            <button class="btn btn-danger" id="confirmBtn" onclick="confirmAction()">Delete</button>
        </div>
    </div>
</div>
<div class="toast-container" id="toastContainer"></div>

<script>
lucide.createIcons();

// Bottom "More" sheet
function alephMore(open){
    document.getElementById('alephSheet').classList.toggle('open', open);
    document.getElementById('alephBd').classList.toggle('open', open);
}
document.addEventListener('keydown', function(e){ if(e.key==='Escape') alephMore(false); });

// Toast
function showToast(type, msg){
    var c=document.getElementById('toastContainer'); var t=document.createElement('div');
    t.className='toast '+type;
    var icon=type==='success'?'check-circle':type==='error'?'alert-circle':type==='warning'?'alert-triangle':'info';
    t.innerHTML='<i data-lucide="'+icon+'"></i><span>'+msg+'</span>'; c.appendChild(t); lucide.createIcons();
    setTimeout(function(){t.style.opacity='0';t.style.transform='translateX(100%)';t.style.transition='all .3s ease';setTimeout(function(){t.remove()},300)},4200);
}

// Confirm dialog
var _confirmCb=null;
function confirmDanger(title,desc,cb){
    document.getElementById('confirmTitle').textContent=title||'Are you sure?';
    document.getElementById('confirmDesc').textContent=desc||'This action cannot be undone.';
    document.getElementById('confirmModal').classList.add('active'); _confirmCb=cb; lucide.createIcons();
}
function confirmAction(){ closeConfirm(); if(_confirmCb)_confirmCb(); }
function closeConfirm(){ document.getElementById('confirmModal').classList.remove('active'); _confirmCb=null; }
</script>
</body>
</html>
