<?php

/**
 * Plugin Name: Moylgrove Mailshot
 * Description: Send email about upcoming events
 * Version: 1.2.2
 * Author: Alan Cameron Wills
 * Licence: GPLv2
 */

 include "mailchimp-keys.php";
 
class MailChimpEmail extends MailChimpKeys
{
    private $mailChimpId = 0;
    private $mailChimpWebId = "";

    private static function mailChimpApi($cmd, $body = null, $method = 'POST')
    {
        $status = 0;
        $output = [];
        try {
			//error_log("Auth: " . self::MailChimpAuthorization);
            $url = self::MailChimpUrl . $cmd;
            $args = ["headers" => ["Authorization" => self::MailChimpAuthorization]];
            //error_log("mailChimpApi " . $url);
            if ($body != null) {
                //error_log (" --- $method ===");
                $args["body"] = json_encode($body);
                $args["method"] = $method;
                //error_log(" --- " . print_r($args, true));
                $result = wp_remote_request($url, $args);
            } else {
                //error_log (" --- GET ===");
                //error_log(" --- " . print_r($args, true));
                $result = wp_remote_get(
                    $url,
                    ["headers" => ["Authorization" => self::MailChimpAuthorization]]
                );
            }
            //error_log(print_r($result, true));

            $status = wp_remote_retrieve_response_code($result);
            $output = json_decode(wp_remote_retrieve_body($result));
            if (400 <= $status) {
                error_log("mailChimpApi $cmd STATUS $status " . print_r($output, true));
            }
        } catch (Exception $e) {
            error_log("mailChimpApi $cmd EXCEPTION " . print_r($e, true));
        }
        return ["status" => $status, "body" => $output];
    }
    // https://mailchimp.com/developer/marketing/api/campaigns/
    public function __construct()
    {
        $response = self::mailChimpApi("campaigns", [
            "type" => "regular",
            "recipients" => ["list_id" => "5e14c66a74"], //Moylgrove Audience ID
            "settings" => [
                "subject_line" => "What's Up in Moylgrove",
                "preview_text" => "At the Old School Hall in the next few weeks",
                "title" => "Monthly " . date("Y-m-j H-i"),
                "from_name" => "Cymdeithas Trewyddel",
                "reply_to" => "info@moylgrove.wales",
                "to_name" => "*|FNAME|* *|LNAME|*",
                "folder_id" => self::FolderID, // monthly
                "auto_footer" => true
            ]
        ]);
        if (200 == $response['status']) {
            //error_log(" Constructor " . print_r($response['body'], true));
            $this->mailChimpId = $response['body']->id;
            $this->mailChimpWebId = self::MailChimpUrl . "campaigns/show/?id={$response['body']->web_id}";
            error_log("  Created $this->mailChimpWebId");
        }
    }

    // https://mailchimp.com/developer/marketing/api/campaign-content/
    public function setContent($html)
    {
        if (!$this->mailChimpId) return null;
        self::mailChimpApi("campaigns/$this->mailChimpId/content", [
            "html" => $html
        ], "PUT");
    }

    public function test()
    {
    	$current_user = wp_get_current_user();
		$email = $current_user->user_email;
        return self::mailChimpApi(
            "campaigns/$this->mailChimpId/actions/test",
            [
                "test_emails" => ["$email"],
                "send_type" => "html"
            ]
        );
    }

    public function send()
    {
        return self::mailChimpApi("campaigns/$this->mailChimpId/actions/send", ["send_type" => "html"]);
    }
	
	public static function ping() {
		$response = self::mailChimpApi("ping");
		return $response;
	}

    public static function clearCampaigns() {
        $response = self::mailChimpApi("campaigns?folder_id=" . self::FolderID);
            $status = $response['status'];
            $output = $response['body'];
		$deleted = 0;
		$count = 0;
        if (200 == $status) {
            forEach ($output->campaigns as $campaign) {
				$count++;
				//error_log("D {$campaign->settings->title}");
                if (isset($campaign->status) && $campaign->status != "sent"
						&& $campaign->settings->folder_id == self::FolderID) {
					// error_log("  DELETE {$campaign->settings->title} ");
                    self::mailChimpApi("campaigns/{$campaign->id}", ['x'=>''], "DELETE");
					$deleted++;
                }
            }
        }
		return ['status'=>$status, 'body'=> "Deleted $deleted / $count"];
    }
}

function sendMailChimp($html, $full = false)
{
    $mail = new MailChimpEmail();
    $mail->setContent($html);
    if ($full) {
        return $mail->send();
    } else {
        return $mail->test();
    }
}

function moylgrove_get_upcoming_events()
{
    // Get posts from database
    $query = [
        'category_name' => "event",
        'order' => "ASC",
        'meta_query' => [
            'relation' => 'AND',
            [
                'key' => 'expires',
                'compare' => 'EXISTS'
            ],
            [
                'key' => 'expires',
                'value' => date('Y-m-d'),
                'compare' => '>',
                'type' => 'DATE'
            ],
            [
                'key' => 'expires',
                'value' => date('Y-m-d', strtotime("+3 months", strtotime(date('Y-m-d')))),
                'compare' => '<',
                'type' => 'DATE'
            ]
        ]
    ];
    $posts = new WP_Query($query);
    $events = [];
    while ($posts->have_posts()):
        $posts->the_post();
        $image_url = get_the_post_thumbnail_url();
        if (has_post_thumbnail()) {
            $image_arr = wp_get_attachment_image_src(get_post_thumbnail_id(), 'medium');
            $image_url = $image_arr[0];
            //error_log("Image " . $image_url);
        }
        $content = apply_filters('the_content', get_the_content());
        $booking = preg_match("/booking/i", $content);

        $events[] = [
            "title" => get_the_title(),
            "id" => get_the_ID(),
            "url" => get_page_link(),
            "src" => $image_url,
            "content" => shortContent($content),
            "booking" => $booking,
            "dt" => strtotime(get_field("dtstart")),
            "date" => date('D j M Y G:i', strtotime(get_field("dtstart"))),
            "subtitle" => get_field("subtitle"),
            "price" => get_field("price")
        ];
    endwhile;
    uasort($events, "compareDates");
    return $events;
}

function compareDates($a, $b)
{
    return $a["dt"] - $b["dt"];
}

function shortContent($content)
{
    return preg_replace("/\n+/", "<br/>", trim(preg_replace(
        "/<.*?>/",
        "",
        join("\n", array_slice(preg_split("/<\/p>/", $content, 3, PREG_SPLIT_NO_EMPTY), 0, 2))
    )));
}

function eventsToHtml($events)
{
    //return print_r($events, true);
    ob_start();
?>
    <style>
        .events img {
            width: 200px;
        }

        .eventHead {
            font-weight: bold;
            font-size: x-large;
        }

        .booking {
            color: darkred;
            background-color: yellow;
            font-weight: bold;
        }

        .ellipsis {
            text-align: right;
        }
    </style>
    <div class="events-wrapped">
        <h2>Coming up in Moylgrove</h2>
        <p>Mostly in the Old School Hall</p>
        <div class="events">
            <?php
            foreach ($events as $event) {
            ?>
                <hr />
                <div class="eventHead">
                    <a href="<?= $event['url'] ?>"><?= $event['title'] ?></a>
                </div>
                <?php
                if ($event['booking']) {
                ?>
                    <div class="booking"><a href="<?= $event['url'] ?>">Booking essential</a></div>
                <?php
                }
                ?>
                <div class="detail">
                    <?= $event['date'] ?><br /><?= $event['price'] ?>
                    <br />
                    <a href="<?= $event['url'] ?>"><img src="<?= $event['src'] ?>" /></a>
                </div>
                <div class="eventFoot">
                    <?= $event['content'] ?>
                </div>
                <div class="ellipsis"><a href="<?= $event['url'] ?>">...</a></div>
            <?php
            }
            ?>
        </div>
        <hr />&nbsp;
        <hr />
        <h4><a href="https://moylgrove.wales/events/">More events...</a></h4>
        <h4><a href="https://moylgrove.wales/hire-the-hall/">Hire the Hall</a></h4>
        <h4><a href="https://moylgrove.wales/">Moylgrove and its history</a></h4>
    </div>

<?php
    return ob_get_clean();
}



/****  SHORTCODE  ****/

function moylgrove_mailshot($attributes = [])
{
    extract(
        shortcode_atts(
            [
                'sendToAll' => '',
                'sendToTest' => ''
            ],
            $attributes
        )
    );
    if (!current_user_can( 'edit_posts' )) return "<h1>Mailshot: Not logged in</h1>";
    
    error_log("moylgrove_mailshot");
    $events = moylgrove_get_upcoming_events();
    $html = eventsToHtml($events);
    $sendCode = date('z');
	
	$cmd = (isset($_GET['ping']) ? "ping" 
		: (isset($_GET['test']) ? "test" 
			: (isset($_GET['send']) && $_GET['send']==$sendCode ? "send" 
				: "")));
	$buttons = "";
	global $wp;
    $thisPage = home_url( $wp->request );
	
	// Avoid accidental sending by browser Back or Refresh
	$buttons .= "<script>history.replaceState(null, '', '$thisPage');</script>";
	
    $buttons .= "<a href='#'><button onclick='if (confirm(\"Send ping?\")){location=\"$thisPage?ping=$sendCode\"}'>Ping MailChimp</button></a> ";
    $buttons .= "<a href='$thisPage'><button>Review content</button></a> ";
    $buttons .= "<a href='$thisPage?test=1'><button>Send test mail</button></a> ";
	if ($cmd != "send") {
		$buttons .= "<a href='#'><button class='send' onclick='if (confirm(\"Send to entire mailing list?\")){location=\"$thisPage?send=$sendCode\"}'>Broadcast mail</button></a>";
	}
	switch ($cmd) {
		case "ping": 
			echo MailChimpEmail::ping();
			break;
		case "test" :
			print_r(sendMailChimp($html));
			break;
		case "send" :
			print_r(sendMailChimp($html, true));
			break;
	}
    return 	"<div><style>button {padding: 0 10px;} button:not(:hover){color:gray !important;} .send{outline:2px solid red;}</style>$buttons</div><hr/>" . $html;

}
add_shortcode("moylgrove-mailshot", "moylgrove_mailshot");



/****  CRON  ****/

function moylgrove_mailshot_cron()
{
    error_log("moylgrove_mailshot_cron");
    $events = moylgrove_get_upcoming_events();
    $html = eventsToHtml($events);
    sendMailChimp($html, true);
}

function moylgrove_mailshot_set_cron($reset=false, $soon=false, $in_a_month=false) {
	$existing = wp_next_scheduled('moylgrove_mailshot_cron_hook');
	$toggleOff = $existing && !$reset && !$soon && !$in_a_month;
	error_log("moylgrove_mailshot_set_cron $existing toggleOff=$toggleOff");
	$when = "";
	if ($reset || $toggleOff) {
		error_log ("  Clear");
		$when = "cron off";
		wp_clear_scheduled_hook('moylgrove_mailshot_cron_hook');
	}
	if (!$toggleOff) {
		$when = $soon ? 'tomorrow 2:14' : ($in_a_month ? '+1 month 2:14' : 'first monday of next month 2:14');
		error_log ("  Reset $when");
	
    	if (!wp_next_scheduled('moylgrove_mailshot_cron_hook')) {
    		error_log ("  Resetting $when");
	    	wp_schedule_event(strtotime( $when ), 'monthly', 'moylgrove_mailshot_cron_hook');
    	}
	}
	return ["status"=>200,"body"=>"$when"];
}


/****  INSTALLATION  ****/

function moylgrove_mailshot_install()
{
    moylgrove_mailshot_set_cron(false, true);
    error_log("Moylgrove Mailshot installed; will run monthly from " 
			  . date('Y-m-j H:i',wp_next_scheduled('moylgrove_mailshot_cron_hook')));
}

add_action("moylgrove_mailshot_cron_hook", "moylgrove_mailshot_cron");


function moylgrove_mailshot_deactivate()
{
	wp_clear_scheduled_hook('moylgrove_mailshot_cron_hook');
	delete_option('moylgrove_mailshot_nonce');
}

function moylgrove_mailshot_uninstall() {
}

register_activation_hook(__FILE__, 'moylgrove_mailshot_install');
register_deactivation_hook(__FILE__, 'moylgrove_mailshot_deactivate');
register_uninstall_hook(__FILE__, 'moylgrove_mailshot_uninstall');


//****  ADMIN PAGE *****/

add_action('wp_ajax_moylgrove_mailshot_ajax', 'moylgrove_mailshot_ajax');


function moylgrove_mailshot_ajax() {
	$cmd = $_POST['go'];
	error_log("moylgrove_mailshot_ajax $cmd");
	$ns = get_option('moylgrove_mailshot_nonce');
	check_ajax_referer("moylgrove{$ns}_mailshot");
    $events = moylgrove_get_upcoming_events();
    $html = eventsToHtml($events);
	$result = ["status"=>0,"body"=>""];
	switch ($cmd) {
		case "clear": 
			$result = MailChimpEmail::clearCampaigns();
			break;
		case "ping": 
			$result = MailChimpEmail::ping();
			break;
		case "test" :
			$result = sendMailChimp($html);
			break;
		case "send" :
			$result = sendMailChimp($html, true);
			moylgrove_mailshot_set_cron(true, false, true);
			break;
		case "setcron": 
			$result = moylgrove_mailshot_set_cron();
			break;
	}
	$result["scheduled"] = wp_next_scheduled('moylgrove_mailshot_cron_hook');
	echo json_encode($result);
	wp_die();
}


add_action('admin_menu', 'moylgrove_mailshot_setup_menu');
 
function moylgrove_mailshot_setup_menu(){
    add_menu_page( 'Mailshot', 'Moylgrove Mailshot', 'manage_options', 'moylgrove-mailshot', 'moylgrove_mailshot_admin_page' );
}
 
function moylgrove_mailshot_admin_page(){
	$ns = date('YmjHi');
	$nonce = wp_create_nonce("moylgrove{$ns}_mailshot");
	update_option('moylgrove_mailshot_nonce', $ns);
    ?>
		<style>
			#mailshot-admin {
				max-width:400px;
			}
			.schedule {
				margin: 6px 0;
				display:flex;
				justify-content: space-between;
    			align-items: center;
			}
			button {background-color:white;}
			.events-wrapped { 
				background-color: white; 
				max-width: 100%; 
				padding:10px; 
				outline:1px solid blue; 
				font-size: 16px;
			}
			.events>div { margin: 10px;}
			.events>div.ellipsis {margin:0}
		</style>

	<div id="mailshot-admin">
    <h1>Moylgrove Mailshot</h1>
	<div class="schedule">
		<div id='cron'></div>
		<div><button onclick="sendAjax(this, 'setcron')">Toggle automatic</button></div>
	</div>
	<script>
		function showAjaxResponse (d) {
			const r = document.getElementById("response");
			r.innerHTML = d;
		}
		function showSchedule(ts) {
			let s = !ts ? "No automatic mailshot scheduled" : 
				"Next automatic mailshot: " + new Date(ts * 1000).toLocaleString();
			document.getElementById("cron").innerHTML = s;
		}
		showSchedule(<?= wp_next_scheduled('moylgrove_mailshot_cron_hook') ?>);
		function sendAjax (button, action='ping', confirmation) {
			button.disabled = true;
			setTimeout(()=>button.disabled=false, 3000);
			
			if (!confirmation || confirm(confirmation)) {
				let form = new FormData();
				form.append('action', 'moylgrove_mailshot_ajax');
				form.append('go', action);
				form.append('_ajax_nonce', '<?=$nonce?>');
				fetch(ajaxurl, {
						method: 'POST',
						body: form
					}).then (r=> r.json())
					.then(r => {
						if (r.status != 204) showAjaxResponse(JSON.stringify(r.body));
						if (r.status >= 200 && r.status < 300) {
							button.style.backgroundColor = "lightgreen";
							setTimeout(()=>{button.style.backgroundColor='#e0ffe0'},2000);
						} else {
							button.style.backgroundColor = "red";
							setTimeout(()=>{button.style.backgroundColor='pink'},2000);
						}
						showSchedule(r.scheduled);
					})
					.catch(e=>showAjaxResponse(e)) ;
			}
		}
		
</script>
	<div id="response"></div>
	<div style="margin: 6px 0">
		<button onclick="sendAjax(this, 'clear', 'Clear generated emails?')">Clear drafts</button>
		<button onclick="sendAjax(this, 'ping')">Ping MailChimp</button>
		<button onclick="sendAjax(this, 'test')">Send Test mail</button>
		<button style="outline:1px solid red;" onclick="sendAjax(this, 'send', 'Send mail to whole list?')">Broadcast</button>
	</div>
	
	<div>
		
    <?php
    $events = moylgrove_get_upcoming_events();
    $html = eventsToHtml($events);
		echo $html;
	?>
</div></div>
    <?php
	
}