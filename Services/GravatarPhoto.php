<?php

namespace Modules\WooCommerceCustomerEnrichment\Services;

use App\Customer;

/**
 * Profile photo from Gravatar. d=404 makes Gravatar answer 404 when no
 * avatar exists, so core's setPhotoFromRemoteFile() (which requires HTTP
 * 200) doubles as the existence check — no extra HTTP code here.
 */
class GravatarPhoto
{
    /**
     * Pure: md5 of the trimmed, lowercased email per Gravatar's spec.
     */
    public static function urlFor($email)
    {
        return 'https://www.gravatar.com/avatar/'.md5(strtolower(trim($email))).'?s=200&d=404';
    }

    /**
     * Fill-gaps-only: never replaces an existing photo. Fresh email query so
     * a billing email added earlier in the same run is included.
     *
     * @return bool whether a photo was set (caller saves the customer)
     */
    public static function apply(Customer $customer)
    {
        if (!empty($customer->photo_url)) {
            return false;
        }

        foreach ($customer->emails()->orderBy('id')->pluck('email') as $email) {
            if ($customer->setPhotoFromRemoteFile(self::urlFor($email))) {
                return true;
            }
        }

        return false;
    }
}
