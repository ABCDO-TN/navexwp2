jQuery(document).ready(function($) {

    // Identify the container where you'd like to insert your button.
    // In this example, we append it to the actions bar.
    var $actionsBar = $('.page-title-action:last'); // Adjust the selector as needed

    // Create the export button element
    $actionsBar.after(' <button class="page-title-action" id="woo-order-exporter-button">Shipping Navex</button>');
    // When the export button is clicked, populate selected order IDs and open the modal.
    $('#woo-order-exporter-button').on('click', function(e) {       
        e.preventDefault();
        const $button = $(this);
        $('body').css('cursor', 'wait');
        var orderIDs = [];
        $button.prop('disabled', true);
        $button.css({
            'opacity': '0.5',
            'cursor': 'not-allowed'
        });
        if (confirm("Do you want to confirm shipping with Navex?")) {
            // Collect selected order IDs. In the WooCommerce orders list, checkboxes have name "post[]"            
            let element = document.getElementById("post_ID");
            if (element && element.value) {
                orderIDs.push(element.value);
            }else{
                $('input[name="id[]"]:checked').each(function() {
                    orderIDs.push($(this).val());
                });
            }
            if (orderIDs.length === 0) {
                alert('Please select at least one order.');
                $button.prop('disabled', false);
                $('body').css('cursor', 'default');
                $button.css({
                    'opacity': '1',
                    'cursor': 'default'
                });
                return;
            }else{
                $.ajax({
                    url: window.url_ajax_admin,
                    type: 'POST',
                    data: {
                        action: 'wobu_update_status',
                        nonce: window.url_ajax_admin_none,
                        designation: "",
                        order_ids: orderIDs
                    },
                    success: function(response) {
                        if (response.success) {
                            if(response.data.ErrorId){
                                alert(' Some Order not shipped with Navex : '+response.data.ErrorId+'.');
                            }else{
                                alert(' Navex shipping OK updated.');
                            }                    
                            location.reload(); // Refresh the page to reflect updated order statuses.
                        } else {
                            alert('Error updating orders.');
                        }
                    },
                    error: function() {
                        alert('AJAX error.');
                    },
                    complete: function(){
                        $button.prop('disabled', false);
                        $('body').css('cursor', 'default');
                        $button.css({
                            'opacity': '1',
                            'cursor': 'default'
                        });
                    }
                });
            }
        }
    });

    // Close the modal on clicking the close button.
    $('#woo-order-exporter-modal-close').on('click', function(e) {
        e.preventDefault();
        $('#woo-order-exporter-modal').fadeOut();
    });

    $('#woo-order-shipping-navex').on('click',function(e){
        e.preventDefault();
        var designation = $('#designation').val();
        // Collect selected orders (the checkboxes in the orders table use name="post[]")
        var order_ids = [];
        let element = document.getElementById("post_ID");
        if (element && element.value) {
            order_ids.push(element.value);
        }else{
            $('input[name="id[]"]:checked').each(function() {
                order_ids.push($(this).val());
            });
        }        
        if (order_ids.length === 0) {
            alert('Please select at least one order.');
            return;
        }
        $.ajax({
            url: window.url_ajax_admin,
            type: 'POST',
            data: {
                action: 'wobu_update_status',
                nonce: window.url_ajax_admin_none,
                designation: designation,
                order_ids: order_ids
            },
            success: function(response) {
                if (response.success) {
                    if(response.data.ErrorId){
                        alert(' Some Order not shipped with Navex : '+response.data.ErrorId+'.');
                    }else{
                        alert(' Navex shipping OK updated.');
                    }                    
                    location.reload(); // Refresh the page to reflect updated order statuses.
                } else {
                    alert('Error updating orders.');
                }
            },
            error: function() {
                alert('AJAX error.');
            }
        });
    })
});
