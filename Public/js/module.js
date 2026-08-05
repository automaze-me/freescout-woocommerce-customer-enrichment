/**
 * WooCommerce Customer Enrichment.
 */
$(document).ready(function() {
    $(document).on('click', '.wcce-enrich', function(e) {
        e.preventDefault();

        loaderShow();

        fsAjax({
                action: 'enrich',
                conversation_id: $(this).attr('data-conversation-id')
            },
            laroute.route('woocommercecustomerenrichment.ajax'),
            function(response) {
                loaderHide();
                if (typeof(response.status) != "undefined" && response.status == 'success') {
                    if (response.changed) {
                        window.location.reload();
                    } else {
                        showFloatingAlert('success', response.msg);
                    }
                } else {
                    showFloatingAlert('error', (response && response.msg) ? response.msg : Lang.get("messages.error_occurred"));
                }
            }, true
        );
    });
});
