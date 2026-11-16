</style>
<?php
$pendingPasswordRecoveryCount = 0;
$pendingPasswordRecoveryCountQuery = $conn->query(
    "SELECT COUNT(*) AS pending_count
     FROM customer_password_recovery_requests
     WHERE status = 'pending'"
);
if(!$pendingPasswordRecoveryCountQuery){
    error_log('Admin password recovery notification count failed: ' . $conn->error);
}else{
    $pendingPasswordRecoveryRow = $pendingPasswordRecoveryCountQuery->fetch_assoc();
    $pendingPasswordRecoveryCount = (int) ($pendingPasswordRecoveryRow['pending_count'] ?? 0);
}
?>
<!-- Main Sidebar Container -->
      <aside class="main-sidebar sidebar-dark-primary elevation-4 sidebar-no-expand">
        <!-- Brand Logo -->
        <a href="<?php echo base_url ?>admin" class="brand-link bg-gradient-primary text-sm">
        <img src="<?php echo htmlspecialchars(validate_image($_settings->info('logo')), ENT_QUOTES, 'UTF-8') ?>" data-fallback-src="<?php echo htmlspecialchars(validate_image($_settings->info('cover')), ENT_QUOTES, 'UTF-8') ?>" alt="" class="brand-image elevation-3" style="opacity: .9;width: 1.5rem;height: 1.5rem;max-height: unset;border-radius: .35rem;object-fit:cover" onerror="if(this.dataset.fallbackSrc && this.src !== this.dataset.fallbackSrc){this.src=this.dataset.fallbackSrc}else{this.hidden=true}">
        <span class="brand-text font-weight-light"><?php echo $_settings->info('short_name') ?></span>
        </a>
        <!-- Sidebar -->
        <div class="sidebar os-host os-theme-light os-host-overflow os-host-overflow-y os-host-resize-disabled os-host-transition os-host-scrollbar-horizontal-hidden">
          <div class="os-resize-observer-host observed">
            <div class="os-resize-observer" style="left: 0px; right: auto;"></div>
          </div>
          <div class="os-size-auto-observer observed" style="height: calc(100% + 1px); float: left;">
            <div class="os-resize-observer"></div>
          </div>
          <div class="os-content-glue" style="margin: 0px -8px; width: 249px; height: 646px;"></div>
          <div class="os-padding">
            <div class="os-viewport os-viewport-native-scrollbars-invisible" style="overflow-y: scroll;">
              <div class="os-content" style="padding: 0px 8px; height: 100%; width: 100%;">
                <!-- Sidebar user panel (optional) -->
                <div class="clearfix"></div>
                <!-- Sidebar Menu -->
                <nav class="mt-4">
                   <ul class="nav nav-pills nav-sidebar flex-column text-sm nav-compact nav-flat nav-child-indent nav-collapse-hide-child" data-widget="treeview" role="menu" data-accordion="false">
                    <li class="nav-item dropdown">
                      <a href="./" class="nav-link nav-home">
                        <i class="nav-icon fas fa-tachometer-alt"></i>
                        <p>
                          Dashboard
                        </p>
                      </a>
                    </li> 
                    <li class="nav-item dropdown">
                      <a href="<?php echo base_url ?>admin/?page=bookings" class="nav-link nav-bookings">
                        <i class="nav-icon fas fa-tasks"></i>
                        <p>
                          Booking List
                        </p>
                      </a>
                    </li>
                    <li class="nav-item dropdown">
                      <a href="<?php echo base_url ?>admin/?page=bookings/notifications" class="nav-link nav-bookings_notifications">
                        <i class="nav-icon fas fa-bell"></i>
                        <p>
                          Customer Notifications
                          <?php if($pendingPasswordRecoveryCount > 0): ?>
                            <span class="right badge badge-warning"><?= $pendingPasswordRecoveryCount > 99 ? '99+' : $pendingPasswordRecoveryCount ?></span>
                          <?php endif; ?>
                        </p>
                      </a>
                    </li>
                    <li class="nav-item dropdown">
                      <a href="<?php echo base_url ?>admin/?page=bookings/archived" class="nav-link nav-bookings_archived">
                        <i class="nav-icon fas fa-archive"></i>
                        <p>
                          Archived Bookings
                        </p>
                      </a>
                    </li>
                    <li class="nav-item dropdown">
                      <a href="<?php echo base_url ?>admin/?page=reports" class="nav-link nav-reports">
                        <i class="nav-icon fas fa-chart-bar"></i>
                        <p>Reports</p>
                      </a>
                    </li>
                    <?php if($_settings->userdata('type') == 1): ?>
                    <li class="nav-header">Maintenance</li>
                    <li class="nav-item dropdown">
                      <a href="<?php echo base_url ?>admin/?page=vehicles" class="nav-link nav-vehicles">
                        <i class="nav-icon fas fa-car"></i>
                        <p>
                          Vehicle Types
                        </p>
                      </a>
                    </li>
                    <li class="nav-item dropdown">
                      <a href="<?php echo base_url ?>admin/?page=services" class="nav-link nav-services">
                        <i class="nav-icon fas fa-th-list"></i>
                        <p>
                          Service List
                        </p>
                      </a>
                    </li>
                    <li class="nav-item dropdown">
                      <a href="<?php echo base_url ?>admin/?page=user/list" class="nav-link nav-user/list">
                        <i class="nav-icon fas fa-users-cog"></i>
                        <p>
                          User List
                        </p>
                      </a>
                    </li>
                    <li class="nav-item dropdown">
                      <a href="<?php echo base_url ?>admin/?page=system_info" class="nav-link nav-system_info">
                        <i class="nav-icon fas fa-cogs"></i>
                        <p>
                          Settings
                        </p>
                      </a>
                    </li>
                    <?php endif; ?>
                  </ul>
                </nav>
                <!-- /.sidebar-menu -->
              </div>
            </div>
          </div>
          <div class="os-scrollbar os-scrollbar-horizontal os-scrollbar-unusable os-scrollbar-auto-hidden">
            <div class="os-scrollbar-track">
              <div class="os-scrollbar-handle" style="width: 100%; transform: translate(0px, 0px);"></div>
            </div>
          </div>
          <div class="os-scrollbar os-scrollbar-vertical os-scrollbar-auto-hidden">
            <div class="os-scrollbar-track">
              <div class="os-scrollbar-handle" style="height: 55.017%; transform: translate(0px, 0px);"></div>
            </div>
          </div>
          <div class="os-scrollbar-corner"></div>
        </div>
        <!-- /.sidebar -->
      </aside>
      <script>
    $(document).ready(function(){
      var page = '<?php echo isset($_GET['page']) ? $_GET['page'] : 'home' ?>';
      var s = '<?php echo isset($_GET['s']) ? $_GET['s'] : '' ?>';
      page = page.replace(/\//g,'_');
      console.log(page)

      if($('.nav-link.nav-'+page).length > 0){
             $('.nav-link.nav-'+page).addClass('active')
        if($('.nav-link.nav-'+page).hasClass('tree-item') == true){
            $('.nav-link.nav-'+page).closest('.nav-treeview').siblings('a').addClass('active')
          $('.nav-link.nav-'+page).closest('.nav-treeview').parent().addClass('menu-open')
        }
        if($('.nav-link.nav-'+page).hasClass('nav-is-tree') == true){
          $('.nav-link.nav-'+page).parent().addClass('menu-open')
        }

      }
      $('.nav-link.active').addClass('bg-gradient-primary')
    })
  </script>