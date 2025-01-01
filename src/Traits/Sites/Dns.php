<?php

namespace DVICD\Traits\Sites;

trait Dns
{
    /**
     * Add subdomain to cloudflare DNS.
     *
     * @param int   $server_id  The server id to which we'll point the domain.
     * @param array $args       This will contain an element with the $args['subdomain'] key.
     *
     * @return void
     */
    public function add_dns($server_id, $args)
    {

        // Is cloudflare enabled?
        $cf_enabled = wpcd_get_option('wordpress_app_wc_sites_cf_enable');

        if (! $cf_enabled) {
            return;
        }

        // Get some other values from settings.
        $cf_zone_id = wpcd_get_option('wordpress_app_wc_sites_cf_zone_id');
        $cf_token   = wpcd_get_option('wordpress_app_wc_sites_cf_token');
        $cf_proxy   = ! wpcd_get_option('wordpress_app_wc_sites_cf_disable_proxy');

        // Ip address of the server.
        $ipv4 = WPCD_SERVER()->get_ipv4_address($server_id);

        // IPv6 address of the server.
        if (wpcd_get_option('wordpress_app_wc_sites_auto_add_aaaa')) {
            $ipv6 = WPCD_SERVER()->get_ipv6_address($server_id);
        } else {
            $ipv6 = '';
        }

        // Add it to cloudflare.
        WPCD_DNS()->cloudflare_add_subdomain($args['subdomain'], $cf_zone_id, $cf_token, $ipv4, $cf_proxy, $ipv6);
    }

        /**
     * Delete cloudflare DNS entry for domain.
     *
     * @param string $domain     Domain to delete.
     *
     * @return void
     */
    public function delete_dns($domain)
    {

        // Is cloudflare enabled?
        $cf_enabled = wpcd_get_option('wordpress_app_wc_sites_cf_enable');

        if (! $cf_enabled) {
            return;
        }

        // Get some other values from settings.
        $cf_zone_id = wpcd_get_option('wordpress_app_wc_sites_cf_zone_id');
        $cf_token   = wpcd_get_option('wordpress_app_wc_sites_cf_token');

        // delete from cloudflare.
        WPCD_DNS()->cloudflare_delete_subdomain($domain, $cf_zone_id, $cf_token);
    }

}
