<?php

namespace DVICD;

use DVICD\Controllers\SettingController;
use DVICD\Factory\SellSiteFactory;

class DVICDINIT
{
	
	static function init()
	{
		$sellSiteFactory =  new SellSiteFactory();
		// var_dump($sellSiteFactory);die;
		// var_dump(empty(WPCD()->classes['wpcd_app_wordpress_sell_wc_site_subs']));die;
		if (empty(WPCD()->classes['wpcd_app_wordpress_sell_wc_site_subs'])) {
			WPCD()->classes['wpcd_app_wordpress_sell_wc_site_subs'] =  $sellSiteFactory->createObject();
		}


		if (empty(WPCD()->classes['wpcd_app_wordpress_wc_sell_wp_site_subs_settings'])) {
			WPCD()->classes['wpcd_app_wordpress_wc_sell_wp_site_subs_settings'] =  new SettingController();
		}
	}


}
