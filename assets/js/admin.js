jQuery(document).ready(function($) {

    // Get tracking code button
    $('#navexwp_get_tracking_code').on('click', function() {
        var button = $(this);
        var orderId = button.data('order-id');
        
        button.attr('disabled', 'disabled').text(navexwp_vars.getting_code);
        
        $.ajax({
            url: navexwp_vars.ajax_url,
            type: 'POST',
            data: {
                action: 'navexwp_get_tracking_code',
                order_id: orderId,
                nonce: navexwp_vars.nonce
            },
            success: function(response) {
                button.removeAttr('disabled').text('Get Code');
                
                if (response.success) {
                    $('#navexwp_tracking_code').val(response.data.tracking_code);
                    $('#navexwp_tracking_status').text(response.data.status);
                    location.reload();
                } else {
                    alert(navexwp_vars.error_message + response.data);
                }
            },
            error: function() {
                button.removeAttr('disabled').text('Get Code');
                alert(navexwp_vars.error_message + 'Server error');
            }
        });
    });
    
    // Check status button
    $('#navexwp_check_status').on('click', function() {
        var button = $(this);
        var orderId = button.data('order-id');
        
        button.attr('disabled', 'disabled').text(navexwp_vars.checking_status);
        
        $.ajax({
            url: navexwp_vars.ajax_url,
            type: 'POST',
            data: {
                action: 'navexwp_check_tracking_status',
                order_id: orderId,
                nonce: navexwp_vars.nonce
            },
            success: function(response) {
                button.removeAttr('disabled').text('Check Status');
                
                if (response.success) {
                    $('#navexwp_tracking_status').text(response.data.status);
                    location.reload();
                } else {
                    alert(navexwp_vars.error_message + response.data);
                }
            },
            error: function() {
                button.removeAttr('disabled').text('Check Status');
                alert(navexwp_vars.error_message + 'Server error');
            }
        });
    });
});