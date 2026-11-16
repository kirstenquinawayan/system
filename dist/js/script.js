function start_loader() {
    $('body').append('<div id="preloader"><div class="loader-holder"><div></div><div></div><div></div><div></div>')
}

function end_loader() {
    $('#preloader').fadeOut('fast', function() {
        $('#preloader').remove();
    })
}
// function 
window.alert_toast = function($msg = 'TEST', $bg = 'success', $pos = '') {
    var Toast = Swal.mixin({
        toast: true,
        position: $pos || 'top-end',
        showConfirmButton: false,
        timer: 5000
    });
    Toast.fire({
        icon: $bg,
        title: $msg
    })
}

$(document).ready(function() {
    // Login
    $('#login-frm').submit(function(e) {
        e.preventDefault()
        var $form = $(this)
        var $feedback = $('#login-feedback')
        var $submit = $('#login-submit')
        $feedback.prop('hidden', true).text('')
        $form.find('.is-invalid').removeClass('is-invalid')

        try {
            if ($('#remember-username').is(':checked')) {
                localStorage.setItem('ocwbs_admin_username', $('#username').val())
            } else {
                localStorage.removeItem('ocwbs_admin_username')
            }
        } catch (error) {
            $feedback.text('This browser blocked saved usernames; sign-in will continue without saving it.')
                .prop('hidden', false)
        }

        start_loader()
        $submit.prop('disabled', true)
        $.ajax({
            url: _base_url_ + 'classes/Login.php?f=login',
            method: 'POST',
            data: $form.serialize(),
            dataType: 'json',
            success: function(resp) {
                if (resp.status === 'success') {
                    location.replace(_base_url_ + 'admin')
                    return
                }
                if (resp.status === 'incorrect') {
                    $feedback.text('Incorrect username or password.')
                    $form.find('input[name="username"], input[name="password"]').addClass('is-invalid')
                    $('#username').trigger('focus')
                } else {
                    $feedback.text('We could not sign you in. Please check your details and try again.')
                }
                $feedback.prop('hidden', false)
            },
            error: function() {
                $feedback.text('Unable to connect to the sign-in service. Please try again.')
                    .prop('hidden', false)
            },
            complete: function() {
                end_loader()
                $submit.prop('disabled', false)
            }
        })
    })
        //Establishment Login
    $('#flogin-frm').submit(function(e) {
        e.preventDefault()
        start_loader()
        if ($('.err_msg').length > 0)
            $('.err_msg').remove()
        $.ajax({
            url: _base_url_ + 'classes/Login.php?f=flogin',
            method: 'POST',
            data: $(this).serialize(),
            error: err => {
                console.log(err)

            },
            success: function(resp) {
                if (resp) {
                    resp = JSON.parse(resp)
                    if (resp.status == 'success') {
                        location.replace(_base_url_ + 'faculty');
                    } else if (resp.status == 'incorrect') {
                        var _frm = $('#flogin-frm')
                        var _msg = "<div class='alert alert-danger text-white err_msg'><i class='fa fa-exclamation-triangle'></i> Incorrect username or password</div>"
                        _frm.prepend(_msg)
                        _frm.find('input').addClass('is-invalid')
                        $('[name="username"]').focus()
                    }
                    end_loader()
                }
            }
        })
    })

    //user login
    $('#slogin-frm').submit(function(e) {
            e.preventDefault()
            start_loader()
            if ($('.err_msg').length > 0)
                $('.err_msg').remove()
            $.ajax({
                url: _base_url_ + 'classes/Login.php?f=slogin',
                method: 'POST',
                data: $(this).serialize(),
                error: err => {
                    console.log(err)

                },
                success: function(resp) {
                    if (resp) {
                        resp = JSON.parse(resp)
                        if (resp.status == 'success') {
                            location.replace(_base_url_ + 'student');
                        } else if (resp.status == 'incorrect') {
                            var _frm = $('#slogin-frm')
                            var _msg = "<div class='alert alert-danger text-white err_msg'><i class='fa fa-exclamation-triangle'></i> Incorrect username or password</div>"
                            _frm.prepend(_msg)
                            _frm.find('input').addClass('is-invalid')
                            $('[name="username"]').focus()
                        }
                        end_loader()
                    }
                }
            })
        })
        // System Info
    $('#system-frm').submit(function(e) {
        e.preventDefault()
        start_loader()
        if ($('.err_msg').length > 0)
            $('.err_msg').remove()
        $.ajax({
            url: _base_url_ + 'classes/SystemSettings.php?f=update_settings',
            data: new FormData($(this)[0]),
            cache: false,
            contentType: false,
            processData: false,
            method: 'POST',
            type: 'POST',
            dataType: 'json',
            success: function(resp) {
                if (resp.status == 'success') {
                    // alert_toast("Data successfully saved",'success')
                    location.reload()
                } else if (resp.status == 'failed' && !!resp.msg) {
                    $('#msg').html('<div class="alert alert-danger err_msg">' + resp.msg + '</div>')
                    $("html, body").animate({ scrollTop: 0 }, "fast");
                } else {
                    $('#msg').html('<div class="alert alert-danger err_msg">An Error occured</div>')
                }
                end_loader()
            }
        })
    })
})