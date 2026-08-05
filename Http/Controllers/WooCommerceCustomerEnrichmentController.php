<?php

namespace Modules\WooCommerceCustomerEnrichment\Http\Controllers;

use App\Conversation;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Modules\WooCommerceCustomerEnrichment\Services\ConversationEnricher;

class WooCommerceCustomerEnrichmentController extends Controller
{
    public function ajax(Request $request)
    {
        $response = [
            'status' => 'error',
            'msg'    => '',
        ];

        switch ($request->action) {
            case 'enrich':
                $conversation = Conversation::find($request->conversation_id);

                if (!$conversation) {
                    $response['msg'] = __('Conversation not found');
                    break;
                }
                if (!\Auth::user()->can('view', $conversation)) {
                    $response['msg'] = __('Not enough permissions');
                    break;
                }
                if (!\Module::isActive('woocommerce')
                    || (!\WooCommerce::isApiEnabled() && !\WooCommerce::isMailboxApiEnabled($conversation->mailbox))
                ) {
                    $response['msg'] = __('WooCommerce API is not configured');
                    break;
                }

                $changed = ConversationEnricher::enrich($conversation);

                $response['status']  = 'success';
                $response['changed'] = $changed;
                $response['msg'] = $changed
                    ? __('Customer profile updated from WooCommerce.')
                    : __('No new information found in WooCommerce.');
                break;

            default:
                $response['msg'] = 'Unknown action';
                break;
        }

        if ($response['status'] == 'error' && empty($response['msg'])) {
            $response['msg'] = 'Unknown error occured';
        }

        return \Response::json($response);
    }
}
