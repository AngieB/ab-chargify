<?php
/*
Plugin Name: Chargify WP Angie EDITS
Plugin URI: http://9seeds.com/plugins
Description: Manage subscriptions to WordPress using the Chargify API
Author: Subscription Tools - Programming by 9seeds
Version: 2.0.4.1
Author URI: http://9seeds.com/plugins
*/
 if(!function_exists('_log')){
  function _log( $message ) {
    if( WP_DEBUG === true ){
      if( is_array( $message ) || is_object( $message ) ){
        error_log( print_r( $message, true ) );
      } else {
        error_log( $message );
      }
    }
  }
}

_log('Testing the error message logging');
_log(array('it' => 'works'));

$base = dirname(__FILE__);

include($base.'/lib/ChargifyConnector.php');
include($base.'/lib/ChargifyCreditCard.php');
include($base.'/lib/ChargifyCustomer.php');
include($base.'/lib/ChargifyProduct.php');
include($base.'/lib/ChargifyPaymentProfile.php');
include($base.'/lib/ChargifySubscription.php');

add_shortcode('chargify', array('chargify','subscriptionListShortCode'));
add_shortcode('chargify-protected', array('chargify','partialprotect'));
add_action('admin_menu',array('chargify','control'));

add_action('admin_menu', array('chargify','createMetaAccessBox'));
add_action('save_post', array('chargify','metaAccessBoxSave'));
add_filter('init', array('chargify','subscriptionRedirect'));
add_filter('the_content', array('chargify','subscriptionDisplayError'),15);
add_filter('the_content', array('chargify','displayForm'));
add_filter('wp_loaded', array('chargify','subscriptionPost'));
add_filter('wp_loaded', array('chargify','subscriptionCreate'));
add_action('show_user_profile', array('chargify','userActions'));
add_action('edit_user_profile', array('chargify','userActions'));
add_action('profile_update', array('chargify','userActionsUpdate'));
add_action('admin_print_scripts-toplevel_page_chargify-admin-settings',array('chargify','adminScripts'));
add_action('wp_login',array('chargify','checkSubStatus'),10,2);
register_activation_hook(__FILE__,array("chargify","activate"));
register_deactivation_hook(__FILE__,array("chargify","deactivate"));

class chargify
{
	function checkSubStatus($login,$user)
	{
		$user_id = $user->ID;
		if(empty($user->chargify_custid))
			return;
      _log( __LINE__ );

		$d = get_option('chargify');
		$opt = array("api_key" => $d["chargifyApiKey"],"test_api_key" => $d["chargifyTestApiKey"],"domain" => $d["chargifyDomain"],"test_domain" => $d["chargifyTestDomain"],"test_mode"=>($d["chargifyMode"] == 'test'? TRUE : FALSE));
		$connector = new ChargifyConnector($opt);

		$sub = $connector->getSubscriptionsByCustomerID($user->chargify_custid);

		if(is_array($sub))
		{
			//get user subscriptions
			$usub = get_user_meta($user_id,'chargify_level',true);
			foreach($sub as $s)
			{
				switch($s->getState())
				{
					case 'canceled':
					case 'expired':
					case 'trial_ended':
						if(isset($usub[$s->getProduct()->getHandle()]))
						{
							unset($usub[$s->getProduct()->getHandle()]);
							update_user_meta($user_id,'chargify_level',$usub);
						}
						break;

					case 'paused':
					case 'trialing':
					case 'active':
						if(!isset($usub[$s->getProduct()->getHandle()]))
						{
							$usub[$s->getProduct()->getHandle()] = time();
							update_user_meta($user_id,'chargify_level',$usub);
						}
						break;
				}
			}
		}
	}
	function adminScripts()
	{
        $pluginurl = WP_PLUGIN_URL . '/' . plugin_basename(dirname(__FILE__));
		wp_enqueue_script('jscolor',$pluginurl.'/js/options.js',array('jquery'));
	}
	function manualTrim($text)
	{
        $text = strip_shortcodes( $text );

        $text = apply_filters('the_content', $text);
        $text = str_replace(']]>', ']]&gt;', $text);
        $text = strip_tags($text);
        $excerpt_length = apply_filters('excerpt_length', 55);
        $excerpt_more = apply_filters('excerpt_more', ' ' . '[...]');
        $words = explode(' ', $text, $excerpt_length + 1);
        if (count($words) > $excerpt_length) {
            array_pop($words);
            $text = implode(' ', $words);
            $text = $text . $excerpt_more;
        }
		return $text;
	}

	function products($fresh = false)
	{
		$d = get_option('chargify');
		$opt = array("api_key" => $d["chargifyApiKey"],"test_api_key" => $d["chargifyTestApiKey"],"domain" => $d["chargifyDomain"],"test_domain" => $d["chargifyTestDomain"],"test_mode"=>($d["chargifyMode"] == 'test'? TRUE : FALSE));
		if($fresh)
		{
			$connector = new ChargifyConnector($opt);
			$products = $connector->getAllProducts();
		}
		else
		{
			if(is_array($d['chargifyProducts']))
			{
				$products = array();
				foreach($d['chargifyProducts'] as $p)
				{
					if($p['enable'] == 'on')
						$products[] = unserialize(base64_decode($p['raw']));
				}
			}
		}
		return $products;
	}

	function webhooks()
	{
		$d = get_option('chargify');
		$opt = array("api_key" => $d["chargifyApiKey"],"test_api_key" => $d["chargifyTestApiKey"],"domain" => $d["chargifyDomain"],"test_domain" => $d["chargifyTestDomain"],"test_mode"=>($d["chargifyMode"] == 'test'? TRUE : FALSE));
		$connector = new ChargifyConnector($opt);
		$webhooks = $connector->getAllWebhooks();

		return $webhooks;
	}

	function subscriptionListShortCode($atts)
	{
		global $current_user;
		extract(shortcode_atts(array('accountingcodes'=>''), $atts));
		$filteraccountingcodes = array();
		if ($accountingcodes != '') {
			$acs = explode(',', $accountingcodes);
			for($i = 0; $i < count($acs); $i++) {
				$filteraccountingcodes[$acs[$i]] = true;
			}
		}
    $u = wp_get_current_user();
		$d = get_option("chargify");
		$return_url = $_GET['return_url'];
		$plan = $_GET['plan'];
    $opt = array("api_key" => $d["chargifyApiKey"],"test_api_key" => $d["chargifyTestApiKey"],"domain" => $d["chargifyDomain"],"test_domain" => $d["chargifyTestDomain"],"test_mode"=>($d["chargifyMode"] == 'test'? TRUE : FALSE));
		$connector = new ChargifyConnector($opt);
    // $pp = $connector->getPaymentProfileBySubscription($u->chargify_custid);
     $pp = $connector->getSubscriptionsByCustomerID($u->chargify_custid);
    //echo $pp->getName() . "\n";
    _log('is it getting custid?');
    _log($u->chargify_custid);
    _log('this is being displayed using connector->getSubscriptionByCustomerID');

    // $profile_id = (string) $pp->ChargifySubscription[0]->credit_card->id;
     _log("Is this thing on?");



		if($_POST['chargifySignupFirst'])
			$first = $_POST['chargifySignupFirst'];
		elseif($current_user->first_name)
			$first = $current_user->first_name;
		else
			$first = '';
		if($_POST['chargifySignupLast'])
			$last = $_POST['chargifySignupLast'];
		elseif($current_user->last_name)
			$last = $current_user->last_name;
		else
			$last = '';
		if($_POST['chargifySignupEmail'])
			$email = $_POST['chargifySignupEmail'];
		elseif($current_user->data->user_email)
			$email = $current_user->data->user_email;
		else
			$email = '';
    if($_POST['chargifySignupBillFirst'])
			$first_name = $_POST['chargifySignupBillFirst'];
		elseif($current_user->first_name)
			$first_name = $current_user->first_name;
		else
			$first_name = '';


		if( is_user_logged_in() && ($d["chargifySignupType"] == 'api'))
		{
			
			$products = self::products();
			$form ='<form name="chargifySignupForm" method="post" action="">
			<input type="hidden" name="chargify_signupcc_noncename" id="chargify_signupcc_noncename" value="'.wp_create_nonce( plugin_basename(__FILE__) ).'" />
			<input type="hidden" name="return_url" value="'.$_GET['return_url'].'">
			<input type="hidden" name="submit" value="">
			<table>
				<tr>
					<th colspan="2"><p><strong>Subscriber Information</strong></p></th>
				</tr>
				<tr>
					<td>First Name</td>
					<td><input type="text" name="chargifySignupFirst" value="'.$first.'"></td>
				</tr>
				<tr>
					<td>Last Name</td>
					<td><input type="text" name="chargifySignupLast" value="'.$last.'"></td>
				</tr>
				<tr>
					<td>Email</td>
					<td><input type="text" name="chargifySignupEmail" value="'.$email.'"></td>
				</tr>
				<tr>
					<th colspan="2">Payment Info</th>
				</tr>
				';
			$productdisplayed = 0;
			foreach($products as $p)
			{
				if ((isset($filteraccountingcodes[$p->getAccountCode()]) && $filteraccountingcodes[$p->getAccountCode()]) || count($filteraccountingcodes) == 0) {
					$form .= '<tr>';
					$form .= '<td><div align="center"><strong><p>'.$p->getName().'</strong><br>$'.$p->getPriceInDollars().' '.($p->getInterval() == 1 ? 'each '.$p->getIntervalUnit() : 'every '.$p->getInterval().' '.$p->getIntervalUnit().'s').'<br>'.$p->getDescription().'</p></div></td>';
					$form .= '<td><p><input onclick="javascript:document.chargifySignupForm.submit.value=\''.$p->id.'\';" name="submit'.$p->getHandle().'" type="submit" value="'.$p->getName().'"></p></td>';
					$form .= '</tr>';
					$productdisplayed = 1;
				}
			}
			if(!$productdisplayed)
			{
				$form = '<form name="chargifySignupForm" method="post" action=""><table><tr><td colspan="2">No products found</td></tr>';
			}

			$form .= '</table>
			</form>';
		}
		else
		{

			$form ='<form name="chargifySignupForm" method="post" action="">
			<input type="hidden" name="chargify_signup_noncename" id="chargify_signupcc_noncename" value="'.wp_create_nonce( plugin_basename(__FILE__) ).'" />
			<input type="hidden" name="return_url" value="'.$_GET['return_url'].'">
			<input type="hidden" name="submit" value="">
			<table class="chargify-tbl">
				<tr>
					<th colspan="2"><p><strong>Subscriber Information</strong></p></th>
				</tr>
				<tr>
					<td>First Name</td>
					<td><input type="text" name="chargifySignupFirst" value="'.$first.'"></td>
				</tr>
				<tr>
					<td>Last Name</td>
					<td><input type="text" name="chargifySignupLast" value="'.$last.'"></td>
				</tr>
				<tr>
					<td>Email</td>
					<td><input type="text" name="chargifySignupEmail" value="'.$email.'"></td>
				</tr>
				<tr>
					<td>Company Name</td>
				<td><input type="text" name="chargifySignupOrganization" value="'.$organization.'"></td></tr>
				<tr>
					<td>Phone</td>
				<td><input type="text" name="chargifySignupPhone" value="'.$phone.'"></td></tr>';

			$products = self::products();
			$productdisplayed = 0;
			if(is_array($products))
			foreach($products as $p)
			{
				$pNameAdjusted = sanitize_title_with_dashes($p->getName());
				/*
				$planNameAdjusted = str_replace('-', ' ', $plan);
				$pName = strtolower($planName);
				$pNameAdjusted = strtolower($planNameAdjusted);
				*/
				if ((isset($filteraccountingcodes[$p->getAccountCode()]) && $filteraccountingcodes[$p->getAccountCode()]) || count($filteraccountingcodes) == 0)
				{
					if(!isset($plan))
					{
						$form .= '<tr>';
						$form .= '<td><div align="center"><strong><p>'.$p->getName().'</strong><br>$'.$p->getPriceInDollars().' '.($p->getInterval() == 1 ? 'each '.$p->getIntervalUnit() : 'every '.$p->getInterval().' '.$p->getIntervalUnit().'s').'<br>'.$p->getDescription().'</p></div></td>';
						if(isset($current_user->chargify_level[$p->getHandle()]))
						{
							$form .= '<td>You already have access to this level</td>';
						}
						else
						{
							$form .= '<td><p><input onclick="javascript:document.chargifySignupForm.submit.value=\''.$p->id.'\';" name="submit'.$p->getHandle().'" type="submit" value="'.$p->getName().'"></p></td>';

							$form .= '</tr>';
							$productdisplayed = 1;
						}
					}
					else
					{
						if($plan == $pNameAdjusted)
						{
							if(isset($current_user->chargify_level[$p->getHandle()]))
							{
								$form .= '<td colspan="2">You already have access to this level</td>';
							}
							else
							{
								$form .= '<td colspan="2"><p><input onclick="javascript:document.chargifySignupForm.submit.value=\''.$p->id.'\';" name="submit'.$p->getHandle().'" type="submit" value="Continue >"></p></td>';
							}
						}
						else
							$form .= '';

						$form .= '</tr>';
						$productdisplayed = 1;
					}
				}
			}

			if(!$productdisplayed)
			{
				$form = '<form name="chargifySignupForm" method="post" action=""><table><tr><td colspan="2">No products found</td></tr>';
			}
			$form .= '</table>';
			$form .= '</form>';
		}
		return $form;
	}

	function userActionsUpdate($user_id)
	{
		global $current_user;

		if(isset($_POST["chargifyCancelSubscription"]) && is_numeric($_POST['chargifyCancelSubscription']) && ($user_id == $current_user->ID || current_user_can('activate_plugins')))
		{
			$d = get_option('chargify');
			$opt = array("api_key" => $d["chargifyApiKey"],"test_api_key" => $d["chargifyTestApiKey"],"domain" => $d["chargifyDomain"],"test_domain" => $d["chargifyTestDomain"],"test_mode"=>($d["chargifyMode"] == 'test'? TRUE : FALSE));
			$connector = new ChargifyConnector($opt);
			$connector->cancelSubscription($_POST["chargifyCancelSubscription"]);

			//get rid of it on our side
			$sub = $connector->getSubscriptionsBySubscriptionID($_POST['chargifyCancelSubscription']);
			$usub = get_user_meta($user_id,'chargify_level',true);
			unset($usub[$sub->getProduct()->getHandle()]);
			update_user_meta($user_id,'chargify_level',$usub);
		}
	}
	function userActions($u)
	{
		if(!strlen($u->chargify_custid))
        	return 0;

		echo '<h3>Chargify Subscription</h3>';
		$d = get_option('chargify');
		$opt = array("api_key" => $d["chargifyApiKey"],"test_api_key" => $d["chargifyTestApiKey"],"domain" => $d["chargifyDomain"],"test_domain" => $d["chargifyTestDomain"],"test_mode"=>($d["chargifyMode"] == 'test'? TRUE : FALSE));
		$connector = new ChargifyConnector($opt);
		$sub = $connector->getSubscriptionsByCustomerID($u->chargify_custid);
		if(is_array($sub))
		{
			foreach($sub as $s)
			{
				echo '<strong>'.$s->getProduct()->getName().'</strong><br>$'.$s->getProduct()->getPriceInDollars().' '.($s->getProduct()->getInterval() == 1 ? 'each '.$s->getProduct()->getIntervalUnit() : 'every '.$s->getProduct()->getInterval().' '.$s->getProduct()->getIntervalUnit().'s').'<br>'.$s->getProduct()->getDescription() . '<br>Subscription Status:<strong>'.$s->getState().'</strong>';
				//echo '<input type="checkbox" name="chargifyCancelSubscription" value="'.$s->id.'"><strong>Check this box to cancel this subscription</strong>';
			}
		}
	}

	function subscriptionCreate()
	{
		global $current_user;
		if(isset($_GET["customer_reference"]) && isset($_GET["subscription_id"]) && !isset($_REQUEST["chargify.subscriptionPost"]))
		{
			$d = get_option('chargify');
			$opt = array("api_key" => $d["chargifyApiKey"],"test_api_key" => $d["chargifyTestApiKey"],"domain" => $d["chargifyDomain"],"test_domain" => $d["chargifyTestDomain"],"test_mode"=>($d["chargifyMode"] == 'test'? TRUE : FALSE));
			$connector = new ChargifyConnector($opt);
			$sub = $connector->getSubscriptionsBySubscriptionId($_GET["subscription_id"]);
			if($sub->getState() == 'active' || $sub->getState() == 'trialing')
			{
				$trans = get_transient('chargify-'.$_GET['customer_reference']);
				if(is_array($trans) && isset($trans['user_email']))
				{
					$email = $trans['user_email'];
					$user_pass = $trans['user_pass'];

					$args = array(
						'user_login' => $email,
						'user_pass' => $user_pass,
						'user_email' => $email,
					);

					if(isset($trans['existing_user']) && $trans['existing_user'] == true)

						return $user_id;

					else
						$user_id = wp_insert_user($args);

					if(is_wp_error($user_id))
					{
						$_POST['chargify_signup_error'] = $user_id->get_error_message();
					}
					else
					{
						//It's possible to hit this section twice depending on configuration
						//this ensures that it won't do all this work twice
						//it's a filthy hack but it works for now
						$_REQUEST["chargify.subscriptionPost"] = $user_id;
						delete_transient('chargify-'.$_GET['customer_reference']);


						update_user_meta( $user_id, 'chargify_level', array($sub->getProduct()->getHandle()=>time()));
						update_user_meta( $user_id, 'chargify_custid', $sub->getCustomer()->getId());
            update_user_meta( $user_id, 'chargify_payid', $sub->getCustomer()->getPaymentProfileID());
                      _log("is meta updating?");
						if(isset($trans['existing_user']) && $trans['existing_user'] == true)
						{
							if($trans['return_url'])
								$return_url = $trans['return_url'];
							else
								$return_url = site_url();
							wp_redirect($return_url);
							exit;
						}
						else
						{
							wp_new_user_notification($user_id, $user_pass);
							self::login( $user_id, $email,$trans['return_url']);
						}
					}
				}
			}
		}
	}
	function subscriptionRedirect()
	{
		global $current_user;
		if ( wp_verify_nonce( $_POST['chargify_signup_noncename'], plugin_basename(__FILE__) ) && is_numeric($_POST["submit"]))
		{
			if(!is_email($_POST["chargifySignupEmail"]) || !strlen($_POST["chargifySignupFirst"]) || !strlen($_POST["chargifySignupLast"]))
			{
				$_POST["chargify_signup_error"] = array('ERROR'=>"All fields are required. Please enter a name and valid email address");
				return 0;
			}

			$d = get_option("chargify");
			$user_login = sanitize_user( $_POST["chargifySignupEmail"] );
			$user_email = apply_filters( 'user_registration_email', $_POST["chargifySignupEmail"] );
			if((username_exists($user_login) || email_exists($user_email)) && !$current_user->ID)
			{
				echo "User exists";
			}
			else
			{
				$user_pass = wp_generate_password();
				$return_url = $_REQUEST['return_url'];
				$trans = array();
				$trans["user_login"] = $user_login;
				$trans["user_email"] = $user_email;
				$trans["user_pass"] = $user_pass;
				$trans["return_url"] = $return_url;

        _log("user already logged in");
				//current user already logged in...
				if($current_user->ID)
					$trans['existing_user'] = true;

				set_transient("chargify-".md5($user_email.$_POST['submit'].time()),$trans);

				$opt = array("api_key" => $d["chargifyApiKey"],"test_api_key" => $d["chargifyTestApiKey"],"domain" => $d["chargifyDomain"],"test_domain" => $d["chargifyTestDomain"],"test_mode"=>($d["chargifyMode"] == 'test'? TRUE : FALSE));
				$connector = new ChargifyConnector($opt);
				$product = $connector->getProductByID($_POST['submit']);

				$pubpage = array_shift($product->public_signup_pages);
				if(is_array($pubpage))
				{
					$uri = '?first_name='.urlencode($_POST["chargifySignupFirst"]).'&last_name='.urlencode($_POST["chargifySignupLast"]).'&email='.urlencode($_POST["chargifySignupEmail"]).'&organization='.urlencode($_POST["chargifySignupOrganization"]).'&phone='.urlencode($_POST["chargifySignupPhone"]).'&reference='.urlencode(md5($user_email.$_POST['submit'].time()));

					header("Location: ".$pubpage['url'].$uri);
					exit;

					/*
					if($d["chargifyMode"] == 'test')
					{
						header("Location: https://".$d["chargifyTestDomain"].".chargify.com/h/".$_POST["submit"]."/subscriptions/new".$uri);
						exit;
					}
					else
					{
						header("Location: https://".$d["chargifyDomain"].".chargify.com/h/".$_POST["submit"]."/subscriptions/new".$uri);
						exit;
					}
					 */
				}
			}
		}
		if(function_exists('json_decode') && $_SERVER["CONTENT_TYPE"] === 'application/json')
		{
			global $wpdb;
			$sub_ids = json_decode(file_get_contents('php://input'));

			if($sub_ids !== NULL && is_array($sub_ids))
			{
				$d = get_option('chargify');
				$opt = array("api_key" => $d["chargifyApiKey"],"test_api_key" => $d["chargifyTestApiKey"],"domain" => $d["chargifyDomain"],"test_domain" => $d["chargifyTestDomain"],"test_mode"=>($d["chargifyMode"] == 'test'? TRUE : FALSE));
				$connector = new ChargifyConnector($opt);
				foreach($sub_ids as $id)
				{
					$sub = $connector->getSubscriptionsBySubscriptionId($id);
					if($sub->getStatus() == 'canceled')
					{
						$cur = $wpdb->get_row( $wpdb->prepare("SELECT * FROM $wpdb->usermeta WHERE meta_key = 'chargify_custid' AND meta_value = %s", $sub->getCustomer()->getId() ) );
						if ( $cur && $cur->user_id )
						{
							delete_user_meta( $cur->user_id, 'chargify_level');
						}
					}
				}
			}
		}
	}
	function updateSubscription()
	{
		$d = get_option('chargify');
		$u = wp_get_current_user();

		if(!is_array($u->chargify_level))
			update_user_meta($u->ID,'chargify_level',array($u->chargify_level=>strtotime($u->user_registered)));

		$opt = array("api_key" => $d["chargifyApiKey"],"test_api_key" => $d["chargifyTestApiKey"],"domain" => $d["chargifyDomain"],"test_domain" => $d["chargifyTestDomain"],"test_mode"=>($d["chargifyMode"] == 'test'? TRUE : FALSE));
		$connector = new ChargifyConnector($opt);
		$subs = $connector->getSubscriptionsByCustomerID($u->chargify_custid);
		if(is_array($subs))
		foreach($subs as $sub)
		{
			if($sub->getState() == 'canceled')
			{
				$levels = get_user_meta($u->ID,'chargify_level',true);
				unset($levels[$sub->getProduct()->getHandle()]);
				update_user_meta( $u->ID, 'chargify_level',$levels);
			}
		}
		update_user_meta($u->ID,'chargify_access_check',strtotime("+1 days"));
	}

	function subscriptionDisplayError($content)
	{
		//check to see if there was an error in the form processing step in chargifyRedirect
		if(is_array($_POST["chargify_signup_error"]) && isset($_POST["chargify_signup_error"]['ERROR']))
		{
			$d = get_option("chargify");
			if($_POST["chargify_signup_error"]['ERROR'] == '<strong>'.$d['chargifyThankYou'].'</strong>')
				return $_POST["chargify_signup_error"]['ERROR'];
			else
				return $_POST["chargify_signup_error"]['ERROR'].$content;
		}
		return $content;
	}
	function displayForm($content)
	{
		global $post;
		$d = get_option('chargify');

		if(get_permalink($post->ID) == $d['chargifySignupLink'] && !stristr($content,'[chargify'))
		{
			if($d['chargifyOrderFormPos'] == 'bottom')
				$content = $content.do_shortcode('[chargify]');
			else
				$content = do_shortcode('[chargify]').$content;
		}

		return $content;
	}

  
 function subscriptionPost($content)
	 {
    if ( is_user_logged_in() ) {  
		$d = get_option("chargify");

		//Process full CC single form
		if ( wp_verify_nonce( $_POST['chargify_signupcc_noncename'], plugin_basename(__FILE__) ))
		{
			$d = get_option('chargify');
			$opt = array("api_key" => $d["chargifyApiKey"],"test_api_key" => $d["chargifyTestApiKey"],"domain" => $d["chargifyDomain"],"test_domain" => $d["chargifyTestDomain"],"test_mode"=>($d["chargifyMode"] == 'test'? TRUE : FALSE));
			$connector = new ChargifyConnector($opt);
		
    /* This is where I need to insert $pay_id + other subscription elements.. ie, product_id, organization, etc. and remove the elements that coincide with payment_profile, so remove billing info*/


			if((username_exists($user_login) || email_exists($user_email)) && !$current_user->ID)
			{
				$newsub = $connector->newSubscription($xml);
        if(!$_POST['return_url'])
          $_POST['return_url'] = site_url();

        self::login( $user_id, $user_email,$_POST['return_url']);
			}

  }
     }

		else {
	
		$d = get_option("chargify");

		//Process full CC single form
		if ( wp_verify_nonce( $_POST['chargify_signupcc_noncename'], plugin_basename(__FILE__) ))
		{
			$d = get_option('chargify');
			$opt = array("api_key" => $d["chargifyApiKey"],"test_api_key" => $d["chargifyTestApiKey"],"domain" => $d["chargifyDomain"],"test_domain" => $d["chargifyTestDomain"],"test_mode"=>($d["chargifyMode"] == 'test'? TRUE : FALSE));
			$connector = new ChargifyConnector($opt);
			$xml = '<?xml version="1.0" encoding="UTF-8"?>
			<subscription>
				<product_id>' . $_POST["submit"] . '</product_id>
				<customer_attributes>
				<first_name>'.$_POST["chargifySignupFirst"].'</first_name>
				<last_name>'.$_POST["chargifySignupLast"].'</last_name>
				<email>'.$_POST["chargifySignupEmail"].'</email>
				<organization>'.$POST["chargifySignupOrganization"].'</organization>
				<phone>'.$POST["chargifySignupPhone"].'</phone>
				</customer_attributes>
				<credit_card_attributes>
				<first_name>'.$_POST["chargifySignupBillFirst"].'</first_name>
				<last_name>'.$_POST["chargifySignupBillLast"].'</last_name>
				<billing_address>'.$_POST["chargifySignupBillAddress"].'</billing_address>
				<billing_city>'.$_POST["chargifySignupBillCity"].'</billing_city>
				<billing_state>'.$_POST["chargifySignupBillState"].'</billing_state>
				<billing_zip>'.$_POST["chargifySignupBillZip"].'</billing_zip>
				<billing_country>'.$_POST["chargifySignupBillCountry"].'</billing_country>
				<full_number>'.$_POST["chargifySignupBillCc"].'</full_number>
				<expiration_month>'.$_POST["chargifySignupExpMo"].'</expiration_month>
				<expiration_year>'.$_POST["chargifySignupExpYr"].'</expiration_year>
				</credit_card_attributes>
			</subscription>';

			$user_login = sanitize_user( $_POST["chargifySignupEmail"] );
			$user_email = apply_filters( 'user_registration_email', $_POST["chargifySignupEmail"] );
		/*	if((username_exists($user_login) || email_exists($user_email)) && !$current_user->ID)
			{
				$newsub = $connector->newSubscription($xml);
        if(!$_POST['return_url'])
          $_POST['return_url'] = site_url();

        self::login( $user_id, $user_email,$_POST['return_url']);
			}
			else
			{*/
				$res = $connector->createCustomerAndSubscription($xml);
				if(strlen($res->error))
				{
					$_POST["chargify_signup_error"]['ERROR'] = '<strong>'.$res->error.'</strong>';
					return 0;
				}
				else
				{
					$user_pass = wp_generate_password();
					$args = array(
						'user_login' => $user_login,
						'user_pass' => $user_pass,
						'user_email' => $user_email,
					);
					$user_id = wp_insert_user($args);
					wp_new_user_notification($user_id, $user_pass);
					update_user_meta( $user_id, 'chargify_level', array($res->getProduct()->getHandle() => 1));
					update_user_meta( $user_id, 'chargify_custid', $res->getCustomer()->getId());
          //update_user_meta( $user_id, 'chargify_payid', $res->getCustomer()->getId()->getPaymentProfile());
					$_POST["chargify_signup_error"]['ERROR'] = '<strong>'.$d['chargifyThankYou'].'</strong>';

					if(!$_POST['return_url'])
						$_POST['return_url'] = site_url();

					self::login( $user_id, $user_email,$_POST['return_url']);
				}
		}
   }
}

	function metaAccessBoxSave($post_id)
	{
		if ( !wp_verify_nonce( $_POST['access_noncename'], plugin_basename(__FILE__) ))
		{
			return $post_id;
		}
		if ( 'page' == $_POST['post_type'] )
		{
			if ( !current_user_can( 'edit_page', $post_id ))
			{
				return $post_id;
			}
		}
		else
		{
			if ( !current_user_can( 'edit_post', $post_id ))
			{
				return $post_id;
			}
		}
		$levels = array();

		if(is_array($_POST['chargifyAccess']))
			foreach($_POST['chargifyAccess'] as $handle => $access)
				if($access['enable'] == $handle)
					$levels[$handle] = $access['drip'];

		$data["levels"] = $levels;

		update_post_meta($post_id, 'chargify_access', $data);
	}



	function activate()
	{
		$data = array(
			'chargifyApiKey'=>'APIKEY',
			'chargifyTestApiKey'=>'APIKEY',
			'chargifyDomain'=>'domain',
			'chargifyTestDomain'=>'domain',
			'chargifyMode'=>'test',
			'chargifySignupType'=>'default',
			'chargifyNoAccessAction'=>'default',
			'chargifyDefaultNoAccess'=>'You are not allowed to see this post. Please upgrade your account to see this content',
			'chargifySignupLink'=>''
		);

		if(!get_option("chargify"))
        {
            add_option("chargify",$data);
        }
	}

    function deactivate()
    {
        delete_option("chargify");
    }

	function control()
	{
		$pluginurl = WP_PLUGIN_URL . '/' . plugin_basename(dirname(__FILE__));
		add_menu_page('Chargify Options', 'Chargify', 'activate_plugins', 'chargify-admin-settings', array("chargify", "controlForm"),$pluginurl.'/images/chargify.png');
		add_submenu_page('chargify-admin-settings', 'Chargify Option', 'Chargify', 'activate_plugins', 'chargify-admin-settings', array('chargify', 'controlForm'));
		//add_options_page('Chargify Options','Chargify','activate_plugins','chargify-admin-settings',array('chargify','controlForm'));
	}

	function controlForm()
	{
		echo '<style>.wp-editor-wrap{max-width: 700px;}code{display:block}div.even{background:white;margin-top:15px;margin-bottom:15px;padding:10px}div.odd{padding:10px;}</style>';

		$d = get_option("chargify");
        if (isset($_POST['chargify-save-nonce']) && wp_verify_nonce($_POST['chargify-save-nonce'], plugin_basename(__FILE__)))
		{
			if(isset($_REQUEST['chargifySignupLink']) && $_REQUEST['chargifySignupLink'] == 'auto-create')
				$signupurl = get_permalink(wp_insert_post(array('post_type'=>'page','post_title'=>'Chargify','post_status'=>'publish')));
			elseif(isset($_REQUEST['chargifySignupLink']) && $_REQUEST['chargifySignupLink'])
				$signupurl = $_REQUEST['chargifySignupLink'];
			else
				$signupurl = $d['chargifySignupLink'];

			$d["chargifyApiKey"] = $_REQUEST["chargifyApiKey"];
			$d["chargifyTestApiKey"] = $_REQUEST["chargifyTestApiKey"];
			$d["chargifyDomain"] = $_REQUEST["chargifyDomain"];
			$d["chargifyTestDomain"] = $_REQUEST["chargifyTestDomain"];
			$d["chargifyMode"] = $_REQUEST["chargifyMode"];
			$d["chargifyNoAccessAction"] = $_REQUEST["chargifyNoAccessAction"];
			$d["chargifyDefaultNoAccess"] = $_REQUEST["chargifyDefaultNoAccess"];
			//$d["chargifyThankYou"] = $_REQUEST["chargifyThankYou"];
			$d["chargifySignupLink"] = $signupurl;
			$d["chargifySignupType"] = $_REQUEST["chargifySignupType"];
			$d["chargifyOrderFormPos"] = $_REQUEST["chargifyOrderFormPos"];

			$prods = stripslashes_deep($_REQUEST['chargifyproduct']);
			//foreach($prods as $k=>$v)
			//	$prods[$k]['raw'] = $v['raw'];

			$d["chargifyProducts"] = $prods;


			if(isset($_REQUEST['chargifyproduct']) && is_array($_REQUEST['chargifyproduct']))
			{
				$opt = array("api_key" => $d["chargifyApiKey"],"test_api_key" => $d["chargifyTestApiKey"],"domain" => $d["chargifyDomain"],"test_domain" => $d["chargifyTestDomain"],"test_mode"=>($d["chargifyMode"] == 'test'? TRUE : FALSE));
				$connector = new ChargifyConnector($opt);
				foreach($_REQUEST['chargifyproduct'] as $k=> $v)
				{
					if($v['enable'] == 'on')
					{
						$product = $connector->updateProduct($k, array('return_url'=>$signupurl,'accounting_code'=>$v['acctcode'],'description'=>$v['description'],'name'=>$v['name']));
						$d['chargifyProducts'][$k]['raw'] = base64_encode(serialize($product));
					}
				}
			}
			update_option('chargify',$d);

			echo '<div class="updated"><p><strong>Options saved</strong></p></div>';
		}


        echo '<div class="wrap">';
            echo '<form name="chargify" method="post" action="">';
			echo '<input type="hidden" name="chargify-save-nonce" value="'.wp_create_nonce(plugin_basename(__FILE__)).'" />';
			echo '<h2>Chargify Settings</h2>';

			echo '<h2 class="nav-tab-wrapper">';
				echo '<a href="#" class="nav-tab nav-tab-active" id="account">Chargify Account</a>';
				echo '<a href="#" class="nav-tab" id="products">Products</a>';
				echo '<a href="#" class="nav-tab" id="signup">Order Form</a>';
				echo '<a href="#" class="nav-tab" id="messages">Messages and Pages</a>';
				echo '<a href="#" class="nav-tab" id="help">Help</a>';
			echo '</h2>';

			echo '<div id="account" class="chargify-options-hidden-pane">';
            echo '<h3>Account Settings</h3>';
            echo '<p><em>Get this information from your Chargify account. Unless you have multiple accounts, the test values for the API Key and Test Domain are likely the same as their non-test counterparts.</em></p>
            <table class="form-table">
                <tr valign="top">
                    <th scope="row"><label>API Key:</label></th>
                    <td><input type="text" size="40" name="chargifyApiKey" value="'.$d['chargifyApiKey'].'"></td>
                </tr>
                <tr valign="top">
                    <th scope="row"><label>Test API Key:</label></th>
                    <td><input type="text" size="40" name="chargifyTestApiKey" value="'.$d['chargifyTestApiKey'].'"></td>
                </tr>
                <tr valign="top">
                    <th scope="row"><label>Domain:</label></th>
                    <td><input type="text" size="40" name="chargifyDomain" value="'.$d['chargifyDomain'].'">.chargify.com</td>
                </tr>
                <tr valign="top">
                    <th scope="row"><label>Test Domain:</label></th>
                    <td><input type="text" size="40" name="chargifyTestDomain" value="'.$d['chargifyTestDomain'].'">.chargify.com</td>
                </tr>
                <tr valign="top">
                    <th scope="row"><label>Mode:</label></th>
                    <td><input type="radio" name="chargifyMode" value="test" '.($d['chargifyMode'] == 'test' ? 'checked' : '').'>Test <input type="radio" name="chargifyMode" value="live" '.($d['chargifyMode'] == 'live' ? 'checked' : '').'>Live</td>
                </tr>
            </table>';
			echo '</div>';
			echo '<div id="products" class="chargify-options-hidden-pane">';
			echo '<h3>Chargify Product Settings</h3>';
			echo '<style>.chargify-product{margin-bottom:25px;border:1px solid #dfdfdf;max-width:700px;padding:10px;background:white}.disabled{background:#333}.enabled{background:green}.chargify-product-title{color:white;margin-left:-10px;margin-right:-10px;margin-top:-10px;font-size:18pt;padding:10px;margin-bottom:10px;overflow:hidden}.chargify-product textarea{width:100%;height:6em}.chargify-product input[type="text"]{width:50%}.enablebox{float:right;}.sync{display:none}.outofsync{margin-right:25px;color:#c00}</style>';
			$products = self::products(true);
			if(is_array($products))
			foreach($products as $p)
			{
				$sync = 'sync';
				if($d['chargifyProducts'][$p->id]['raw'] != base64_encode(serialize($p)) && $d['chargifyProducts'][$p->id]['enable'] == 'on')
					$sync = 'outofsync';
				$url = $d['chargifySignupLink'].'?plan='.strtolower(sanitize_title_with_dashes($p->getName()));

				echo '<input type="hidden" name="chargifyproduct['.$p->id.'][raw]" value="'.base64_encode(serialize($p)).'">';
				echo '<div class="chargify-product">';
				echo '<div class="chargify-product-title'.($d['chargifyProducts'][$p->id]['enable']?' enabled':' disabled').'">'.$p->getName().'<span class="enablebox"><span class="'.$sync.'">Out of Sync</span><input type="checkbox" name="chargifyproduct['.$p->id.'][enable]"'.($d['chargifyProducts'][$p->id]['enable']?' CHECKED':'').'>Enable</span></div>';
				echo '<div>Name<br><input type="text" name="chargifyproduct['.$p->id.'][name]" value="'.$p->getName().'"></div>';
				echo '<div>Description<br><textarea name="chargifyproduct['.$p->id.'][description]">'.$p->getDescription().'</textarea></div>';
				echo '<div>Accounting Code<br><input type="text" name="chargifyproduct['.$p->id.'][acctcode]" value="'.$p->getAccountCode().'"></div>';
				echo '<div>Direct Link<br>'.$url.'</div>';

				//echo '<div>Return Parameters<br><input type="text" name="chargifyproduct['.$p->id.'][return_params]" value="'.(strlen($p->getReturnParams())?$p->getReturnParams():'subscription_id={subscription_id}&customer_reference={customer_reference}').'"></div>';
				//echo '<div><strong>Return URL: </strong>'.$p->getReturnUrl().'</div>';
				echo '</div>';
			}
			echo '</div>';
			echo '<div id="signup" class="chargify-options-hidden-pane">';
            echo '<h3>Signup Type</h3>';
            echo '<p><em>How will your site process signups. <strong>NOTE: Most users should leave this set to default</strong>.</em></p>
            <table class="form-table">
                <tr valign="top">
                    <th scope="row"><input type="radio" name="chargifySignupType" value="default" '.($d['chargifySignupType'] == 'default' || $d['chargifySignupType'] != 'api' ? 'checked' : '').'>Default</th>
                    <td>When a user creates a subscription they will go to Chargify to enter their payment information and be redirected back to this site to see the thank you page</td>
                </tr>
                <tr valign="top">
                    <th scope="row"><input type="radio" name="chargifySignupType" value="api" '.($d['chargifySignupType'] == 'api' ? 'checked' : '').'>API Style</th>
                    <td><strong>Advanced Option: </strong>When a user creates a subscription they will enter their payment information on this site and the account will be created without the user ever leaving the site.<strong><br />IMPORTANT: Since you will be collecting credit card information in this mode you shouldn\'t activate this without having an SSL certificate on your site!</stron></td>
				</tr>
<tr valign="top">
            </table>';
			echo '<h3>Order Page</h3>';
			echo '<p><em>Choose the page that will display the Chargify subscription order form</em></p>';
			echo '<table class="form-table">';
			echo '<tr valign="top">
				<th scope="row"><label>Link to order page.</label></th>
				<td>';
					echo '<select name="chargifySignupLink" >';
					echo self::pagelist($d['chargifySignupLink']);
					echo '</select>';
					echo '<span class="description">This will get linked on a protected page when a user doesn\'t have access to the content</span><br>';
					if($d['chargifySignupLink'])
						echo '<strong>Currently: </strong>'.get_the_title(url_to_postid( $d['chargifySignupLink'] )).'&nbsp;<a href="'.get_edit_post_link(url_to_postid( $d['chargifySignupLink'] )).'">Edit Order Page</a>';
					else
						echo '<strong>Order Page Not Set</strong>';
			echo '</td>
			</tr>
			<th scope="row">Order Form Position</th>
			<td><select name="chargifyOrderFormPos"><option value="top"'.($d["chargifyOrderFormPos"] == "top" ? " SELECTED" : "").'>Top</option><option value="bottom"'.($d["chargifyOrderFormPos"] == "bottom" ? " SELECTED" : "").'>Bottom</option></select>';
			echo '<span class="description">If there isn\'t a [chargify] shortcode does the orderform get placed on top or bottom of content</span>';
			echo '</td>
			</tr>
            </table>';
			echo '</div>';
			echo '<div id="messages" class="chargify-options-hidden-pane">';
			echo '<h3>No Access Message</h3>
            <p><em>Message to display if user doesn\'t have correct access to the content</em></p>
			<table class="form-table">';
				echo '<tr valign="top">';
					echo'<th scope="row">No Access Action</th>';
					echo '<td><select name="chargifyNoAccessAction" onChange="jQuery(\'#chargifyNoAccessEditor\').toggle()"><option value="default"'.($d["chargifyNoAccessAction"] == "default" ? " SELECTED" : "").'>Show custom message</option><option value="exerpt"'.($d["chargifyNoAccessAction"] == "excerpt" ? " SELECTED" : "").'>Show post excerpt</option></td>';

				echo '</tr>';

                echo '<tr valign="top" id="chargifyNoAccessEditor" style="'.($d['chargifyNoAccessAction']=='excerpt'?'display:none':'').'">';
					echo '<td colspan="2">';
						wp_editor($d['chargifyDefaultNoAccess'],'chargifyDefaultNoAccess');
					echo '</td>';
                echo '</tr>';
                echo '</table>';
			echo '<hr />';
			/*
            echo '<h3>Thank You Message</h3>';
            echo '<table class="form-table">';
			echo '<tr valign="top">';
				echo '<td colspan="2">';
					wp_editor($d['chargifyThankYou'],'chargifyThankYou');
				echo '</td>';
			echo '</tr>';
			echo '</table>';
			 */
			echo '</div>';
   			echo '<div id="help" class="chargify-options-hidden-pane">';
			echo '<h1>Chargify Plugin Configuration Instructions</h1>';
			echo '<div style="width:50%;display:inline-block">';
?>
			<div class="even"><h1>Documentation: <a href="http://chargifywp.com/documentation" target="_blank">View the documentation</a></h1>
				Please use the documentation link for the most up to date and step by step instructions on how to put everything together. For your convenience, we have version 1.0 instructions below in case you find them helpful.
			</div>
			<div class="even"><h2>1. <a id="click-1" class="click-help" href="<?php echo admin_url('admin.php?page=chargify-admin-settings#chargify-signup'); ?>" onClick="javascript:jQuery('#signup').click();">Choose or have the plugin create</a> the order page</h2></div>
			<div class="odd"><h2>2. Enter your <a id="click-2" class="click-help" href="https://app.chargify.com/login.html" target="_blank">Chargify API keys</a> into the <a id="click-3" class="click-help" href="<?php echo admin_url('admin.php?page=chargify-admin-settings#chargify-account'); ?>" onClick="javascript:jQuery('#account').click();">Chargify Account tab</a></h2></div>
			<div class="even"><h2>3. Setup the <a id="click-4" class="click-help" href="https://app.chargify.com/login.html" target="_blank">Return URL and Return Parameters</a> for each product's public signup page</h2>
			<em>This is important and has to happen in your Chargify account otherwise people will not be redirected back to your site after purchase and their accounts will not be created and everyone will become sad. Copy the Return URL and Return Parameters below into their respective slots on the public signup page's settings page</em><br><br>
			<strong>Return URL after successful signup:</strong><br>
			<code>
			<?php echo site_url(); ?>
			</code>
			<strong>Return Parameters:</strong><br>
			<code>
			subscription_id={subscription_id}&customer_reference={customer_reference}
			</code></div>
			<div class="odd"><h2>4. Enable the products you want on your site in the <a id="click-5" class="click-help" href="<?php echo admin_url('admin.php?page=chargify-admin-settings#chargify-products'); ?>" onClick="javascript:jQuery('#products').click();">Products tab</a></h2></div>
			<div class="even"><h2>5. Protect some <a id="click-6" class="click-help" href="<?php echo admin_url(''); ?>">pages or posts</a></h2></div>
			<div class="odd"><h2>6. Test some transactions with CC number of 1, CVV of 123 and any Expiration in the future make sure the subscription_id and customer_reference are passed back as well as <a id="click-7" class="click-help" href="#">making sure we automatically log in</a></h2></div>
			<div class="even"><h2>7. After testing the process set your account from test to live in both the <a id="click-8" class="click-help" href="<?php echo admin_url('admin.php?page=chargify-admin-settings#chargify-account'); ?>" onClick="javascript:jQuery('#account').click();">Chargify Account tab</a> as well as the <a id="click-9" class="click-help" href="https://app.chargify.com/login.html" target="_blank">Chargify Dashboard</a></h2></div>
<?php
			echo '</div>';
			echo '<div id="chargify-help-box" style="width:45%;display:inline-block;position:absolute;">';
			echo '</div>';
			echo '</div>';
         echo '<p class="submit"><input class="button-primary" type="submit" name="Submit" value="Update Options" /></p>';
         echo '</form>';
		 echo '</div>';

		 $pluginurl = WP_PLUGIN_URL . '/' . plugin_basename(dirname(__FILE__));
?>
<script type="text/javascript">
	jQuery(document).ready(function() {
		jQuery('.click-help').mouseover(function(){
			var thing = this.id;
			thing = thing.replace('click-','');
			var h = Math.floor(window.innerWidth/2);
			var v = Math.floor(window.innerHeight/2);
			hh = jQuery(document).scrollTop();
			jQuery('#chargify-help-box').css('right','10px');
			//jQuery('#chargify-help-box').css('top',v+hh-250+'px');
			jQuery('#chargify-help-box').css('top',hh+100+'px');
			jQuery('#chargify-help-box').html('<img style="max-width:100%;height:auto" src="<?php echo $pluginurl; ?>/images/'+ thing + '.jpg" />');
		});
		jQuery('.click-help').mouseout(function(){
			jQuery('#chargify-help-box').html('');
		});
	})
</script>
<?php
	}

	function login( $user_id, $user_login, $return_url )
	{
		if(!strlen($return_url))
			$return_url = site_url();

		$user = get_userdata( $user_id );
		if( ! $user )
			return;
		wp_set_auth_cookie( $user_id, false );
		wp_set_current_user( $user_id, $user_login );
		do_action( 'wp_login', $user_login, $user );

		wp_redirect($return_url);
		exit;
	}
	function pagelist($cur = '')
	{
		ob_start();
		$args = array(
			'post_type'=>'page',
			'post_status'=>'publish',
			'posts_per_page' => -1,
			'orderby' => 'title',
			'order' => 'ASC'
		);
		$pages = get_posts($args);
		echo '<option value="">- Choose a Page -</option>';
		echo '<option value="auto-create">- Auto Create Page -</option>';
		foreach($pages as $p)
		{
			$url = get_permalink($p->ID);
			//echo '<option value="'.$url.'"'.($url == $cur ? ' SELECTED':'').'>'.$p->post_title.'</option>';
			echo '<option value="'.$url.'">'.$p->post_title.'</option>';
		}
		return ob_get_clean();
	}
}
?>
