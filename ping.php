<?php
/**
 * Plugin to refresh PilotPress session to prevent expiration before wordpress timeout
 * 
 * This plugin will mimic a keep-alive ping by performing an ajax request to 
 * ping.php which will update the "rehash" session token tied to PilotPress. 
 * Session Slap will also provide a settings interface to allow admins to 
 * configure smaller end details
 *
 * @package Session Slap
 * @subpackage PilotPress
 * @since 3.5.1
 *
 */

/**
 * jQuery Ping Hooks
 * 
 * Necessary jQuery to do ajax requests to this same file in order 
 * to commit session updates to PilotPress.
 *
 * @since 1.7.1
 *
 */
function pilotpress_sessionslap_face($options){
	$options = get_option("pilotpress-settings");

	//turn on to check if the session slap is working propelry with an alert message
	$options["alerts"] = 0;
	$options['hang_duration'] = 5;

	$options["pilotpress_logout_duration"] = ini_get("session.gc_maxlifetime") / 2;
	if (!isset($options["pilotpress_logout_duration"]) || empty($options["pilotpress_logout_duration"]) || is_null($options["pilotpress_logout_duration"])){
		$options["pilotpress_logout_duration"] = (24 * 60) / 2; // If the value is empty, or access restricted, use PHP's 24 minute default settings.
	}

	?>
	<script type="text/javascript">
	jQuery(function($){
		window.sessionslap = {
			// Show alerts in top right corner?
			"alerts": <?php echo ( $options['alerts'] == 'on' ? 1 : 0 ); ?>,
			// Duration alerts should hang for, in ms
			"alert_hang_time": <?php echo (int) $options['hang_duration'] * 1000; ?>, // 5 seconds
			// Interval of time between each keep-alive ping
			"interval_time": <?php echo (int) $options['pilotpress_logout_duration'] * (60*1000); ?>, //1800000 == 30 min,
			"init": function(){
				window.sessionslap.pinger_interval_id = setInterval( this.pinger, this.interval_time);
			},
			"pinger": function(){
				$(document).trigger("sessionslap.ping.start");
				jQuery.ajax({
					url: "?",
					type: "GET",
					data: {
						update: true,
						r: Math.random()
					},
					success: function(data){
						if (window.sessionslap.alerts){
							window.sessionslap.alert("Your session has been updated!", true );
						}
						$(document).trigger("sessionslap.ping.end.success");
						console.log("Your session has been updated!");
					},
					error: function(data){
						if (window.sessionslap.alerts){
							window.sessionslap.alert("There was an issue with your session getting updated!");
						}
						$(document).trigger("sessionslap.ping.end.error");
						console.log("There was an issue with your session getting updated!");
					}
				});
			},
			"alert": function(msg, good){
				$alert = $("<div>").text( msg ).addClass("sessionslap-alert " + ( good ? "sessionslap-success" : "sessionslap-error" ) ).bind("click", function(e){
					$(this).stop();
				});
				$("body").append( $alert ).find(".sessionslap-alert").delay( this.alert_hang_time ).fadeOut(1500, function(e){
					$(this).remove();
				});
			}
		}
		
		<?php
		if (array_key_exists("pilotpress_logout_users", $options) && $options['pilotpress_logout_users']){
		?>
		window.sessionslap.init();
		<?php
		}
		?>
	});
	</script>
	<?php
}