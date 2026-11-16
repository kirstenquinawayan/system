<script>
  $(document).ready(function(){
    $('#p_use').click(function(){
      uni_modal("Privacy Policy","policy.php","mid-large")
    })
     window.viewer_modal = function($src = ''){
      start_loader()
      var t = $src.split('.')
      t = t[1]
      if(t =='mp4'){
        var view = $("<video src='"+$src+"' controls autoplay></video>")
      }else{
        var view = $("<img src='"+$src+"' />")
      }
      $('#viewer_modal .modal-content video,#viewer_modal .modal-content img').remove()
      $('#viewer_modal .modal-content').append(view)
      $('#viewer_modal').modal({
              show:true,
              backdrop:'static',
              keyboard:false,
              focus:true
            })
            end_loader()  

  }
    window.uni_modal = function($title = '' , $url='',$size=""){
        start_loader()
        $.ajax({
            url:$url,
            error:err=>{
                console.log()
                alert("An error occured")
            },
            success:function(resp){
                if(resp){
                    $('#uni_modal .modal-title').html($title)
                    $('#uni_modal .modal-body').html(resp)
                    if($size != ''){
                        $('#uni_modal .modal-dialog').addClass($size+'  modal-dialog-centered')
                    }else{
                        $('#uni_modal .modal-dialog').removeAttr("class").addClass("modal-dialog modal-md modal-dialog-centered")
                    }
                    $('#uni_modal').modal({
                      show:true,
                      backdrop:'static',
                      keyboard:false,
                      focus:true
                    })
                    end_loader()
                }
            }
        })
    }
    window._conf = function($msg='',$func='',$params = []){
       $('#confirm_modal #confirm').attr('onclick',$func+"("+$params.join(',')+")")
       $('#confirm_modal .modal-body').html($msg)
       $('#confirm_modal').modal('show')
    }
  })
</script>
<?php
$footerName = (string) $_settings->info('name');
$footerLogo = validate_image($_settings->info('logo'));
$footerCover = validate_image($_settings->info('cover'));
?>
<footer class="site-footer">
    <div class="container site-footer-main">
        <div class="site-footer-brand">
            <a class="site-footer-logo" href="./">
                <img src="<?= htmlspecialchars($footerLogo, ENT_QUOTES, 'UTF-8') ?>" data-fallback-src="<?= htmlspecialchars($footerCover, ENT_QUOTES, 'UTF-8') ?>" alt="" onerror="if(this.dataset.fallbackSrc && this.src !== this.dataset.fallbackSrc){this.src=this.dataset.fallbackSrc}else{this.hidden=true}">
                <span><?= htmlspecialchars($footerName, ENT_QUOTES, 'UTF-8') ?></span>
            </a>
            <p>Professional car washing and detailing to keep your vehicle looking its best. Choose a service and book your visit online.</p>
        </div>
        <nav class="site-footer-column" aria-label="Quick links">
            <h2>Quick Links</h2>
            <a href="./">Home</a>
            <a href="./?p=booking&amp;new_booking=1">Booking</a>
            <a href="./?p=services">Our Services</a>
            <a href="./#about">About Us</a>
        </nav>
        <div class="site-footer-column site-footer-contact">
            <h2>Contact Us</h2>
            <a href="mailto:mikkofriday@gmail.com">
                <i class="fa fa-envelope" aria-hidden="true"></i>
                <span>mikkofriday@gmail.com</span>
            </a>
            <p><i class="fa fa-clock-o" aria-hidden="true"></i><span>Mon-Fri: 7:30 AM-11:00 PM<br>Sat-Sun: 7:00 AM-12:00 AM</span></p>
            <a class="site-footer-booking" href="./?p=booking&amp;new_booking=1">Book an appointment</a>
        </div>
    </div>
    <div class="site-footer-bottom">
        <div class="container">
            <p class="m-0">&copy; <?= date('Y') ?> <?= htmlspecialchars($footerName, ENT_QUOTES, 'UTF-8') ?>. All rights reserved.</p>
            <p class="m-0">Developed By: <a href="https://mail.google.com/mail/?view=cm&amp;fs=1&amp;to=mikkofriday@gmail.com" target="_blank" rel="noopener noreferrer">mikkofriday@gmail.com</a></p>
        </div>
    </div>
</footer>

<button type="button" class="customer-floating-theme-toggle" id="customer-floating-theme-toggle" aria-label="Enable dark mode" aria-pressed="false" title="Toggle dark mode">
    <i class="fas fa-moon" aria-hidden="true"></i>
</button>
<script>
    (function(){
        var toggle = document.getElementById('customer-floating-theme-toggle')
        var preferenceToggle = document.getElementById('customer-dark-mode-toggle')
        if(!toggle){
            return
        }
        var icon = toggle.querySelector('i')
        var enabled = document.documentElement.classList.contains('customer-dark-mode')
        function updateToggle(){
            toggle.setAttribute('aria-pressed', enabled ? 'true' : 'false')
            toggle.setAttribute('aria-label', enabled ? 'Disable dark mode' : 'Enable dark mode')
            if(icon){
                icon.classList.toggle('fa-moon', !enabled)
                icon.classList.toggle('fa-sun', enabled)
            }
        }
        updateToggle()
        toggle.addEventListener('click', function(){
            enabled = !document.documentElement.classList.contains('customer-dark-mode')
            document.documentElement.classList.toggle('customer-dark-mode', enabled)
            if(preferenceToggle){
                preferenceToggle.checked = enabled
            }
            updateToggle()
            try{
                window.localStorage.setItem('ocwbs-customer-dark-mode', enabled ? 'true' : 'false')
            }catch(error){
                console.warn('Could not save the display preference.', error)
            }
        })
    })()
</script>

   
    <!-- Resolve conflict in jQuery UI tooltip with Bootstrap tooltip -->
    <script>
      $.widget.bridge('uibutton', $.ui.button)
    </script>
    <!-- Bootstrap 4 -->
    <script src="<?php echo base_url ?>plugins/bootstrap/js/bootstrap.bundle.min.js"></script>
    <!-- ChartJS -->
    <script src="<?php echo base_url ?>plugins/chart.js/Chart.min.js"></script>
    <!-- Sparkline -->
    <script src="<?php echo base_url ?>plugins/sparklines/sparkline.js"></script>
    <!-- Select2 -->
    <script src="<?php echo base_url ?>plugins/select2/js/select2.full.min.js"></script>
    <!-- JQVMap -->
    <script src="<?php echo base_url ?>plugins/jqvmap/jquery.vmap.min.js"></script>
    <script src="<?php echo base_url ?>plugins/jqvmap/maps/jquery.vmap.usa.js"></script>
    <!-- jQuery Knob Chart -->
    <script src="<?php echo base_url ?>plugins/jquery-knob/jquery.knob.min.js"></script>
    <!-- daterangepicker -->
    <script src="<?php echo base_url ?>plugins/moment/moment.min.js"></script>
    <script src="<?php echo base_url ?>plugins/daterangepicker/daterangepicker.js"></script>
    <!-- Tempusdominus Bootstrap 4 -->
    <script src="<?php echo base_url ?>plugins/tempusdominus-bootstrap-4/js/tempusdominus-bootstrap-4.min.js"></script>
    <!-- Summernote -->
    <script src="<?php echo base_url ?>plugins/summernote/summernote-bs4.min.js"></script>
    <script src="<?php echo base_url ?>plugins/datatables/jquery.dataTables.min.js"></script>
    <script src="<?php echo base_url ?>plugins/datatables-bs4/js/dataTables.bootstrap4.min.js"></script>
    <script src="<?php echo base_url ?>plugins/datatables-responsive/js/dataTables.responsive.min.js"></script>
    <script src="<?php echo base_url ?>plugins/datatables-responsive/js/responsive.bootstrap4.min.js"></script>
    <!-- overlayScrollbars -->
    <!-- <script src="<?php echo base_url ?>plugins/overlayScrollbars/js/jquery.overlayScrollbars.min.js"></script> -->
    <!-- AdminLTE App -->
    <script src="<?php echo base_url ?>dist/js/adminlte.js"></script>
    <div class="daterangepicker ltr show-ranges opensright">
      <div class="ranges">
        <ul>
          <li data-range-key="Today">Today</li>
          <li data-range-key="Yesterday">Yesterday</li>
          <li data-range-key="Last 7 Days">Last 7 Days</li>
          <li data-range-key="Last 30 Days">Last 30 Days</li>
          <li data-range-key="This Month">This Month</li>
          <li data-range-key="Last Month">Last Month</li>
          <li data-range-key="Custom Range">Custom Range</li>
        </ul>
      </div>
      <div class="drp-calendar left">
        <div class="calendar-table"></div>
        <div class="calendar-time" style="display: none;"></div>
      </div>
      <div class="drp-calendar right">
        <div class="calendar-table"></div>
        <div class="calendar-time" style="display: none;"></div>
      </div>
      <div class="drp-buttons"><span class="drp-selected"></span><button class="cancelBtn btn btn-sm btn-default" type="button">Cancel</button><button class="applyBtn btn btn-sm btn-primary" disabled="disabled" type="button">Apply</button> </div>
    </div>
    <div class="jqvmap-label" style="display: none; left: 1093.83px; top: 394.361px;">Idaho</div>