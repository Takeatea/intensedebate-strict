<?php
// Custom IntenseDebate Comments template.
// Loads comments from the WordPress database using your own comments template,
// loads IntenseDebate UI for users with Javascript enabled.

if ( get_option( 'id_revertMobile' ) == 0 && id_is_mobile() ) :
	// Display the comments template from the active theme
	include( get_option( 'id_comment_template_file' ) );
else :
	global $id_cur_post;
	$id_cur_post = $post;
	id_auto_login();
?>
	<div id='idc-container'></div>
	<div id="idc-noscript">
		<p id="idc-unavailable"><?php _e( 'This website uses <a href="http://intensedebate.com/">IntenseDebate comments</a>, but they are not currently loaded because either your browser doesn\'t support JavaScript, or they didn\'t load fast enough.', 'intensedebate' ); ?></p>
		<?php
		// Include your theme's comemnt template
		include( get_option( "id_comment_template_file" ) );

		// Queue up the comment UI to load once the window is loaded
		id_postload_js( ID_BASEURL . '/js/wordpressTemplateCommentWrapper2.php?acct=' . get_option( 'id_blogAcct' ) . '&postid=' . $id . '&title=' . urlencode( $post->post_title ) . '&url=' . urlencode( get_permalink( $id ) ) . '&posttime=' . urlencode( $post->post_date_gmt ) . '&postauthor=' . urlencode( get_author_name( $post->post_author ) ) . '&guid=' . urlencode( $post->guid ), 'idc-comment-wrap-js' );
		?>
	</div>
	<script type="text/javascript">
	/* <![CDATA[ */
	function IDC_revert() { if ( !document.getElementById('IDCommentsHead') ) { document.getElementById('idc-loading-comments').style.display='none'; document.getElementById('idc-noscript').style.display='block'; document.getElementById('idc-comment-wrap-js').parentNode.removeChild(document.getElementById('idc-comment-wrap-js')); } else { document.getElementById('idc-noscript').style.display='none'; } }
	idc_ns = document.getElementById('idc-noscript');
	idc_ns.style.display='none'; idc_ld = document.createElement('div');
	idc_ld.id = 'idc-loading-comments'; idc_ld.style.verticalAlign='middle';
	idc_ld.innerHTML = "<img src='<?php bloginfo( 'wpurl' ); ?>/wp-admin/images/loading.gif' alt='Loading' border='0' align='absmiddle' /> Loading IntenseDebate Comments...";
	idc_ns.parentNode.insertBefore(idc_ld, idc_ns);
	addLoadEvent( function(){setTimeout( IDC_revert, 5000 );} );
	/* ]]> */
	</script>
<?php
endif;

function id_is_mobile() {
	$op = strtolower($_SERVER['HTTP_X_OPERAMINI_PHONE']);
	$ua = strtolower($_SERVER['HTTP_USER_AGENT']);
	$ac = strtolower($_SERVER['HTTP_ACCEPT']);
	$ip = $_SERVER['REMOTE_ADDR'];

	 $isMobile = strpos($ac, 'application/vnd.wap.xhtml+xml') !== false
        || $op != ''
        || strpos($ua, 'sony') !== false 
        || strpos($ua, 'symbian') !== false 
        || strpos($ua, 'nokia') !== false 
        || strpos($ua, 'samsung') !== false 
        || strpos($ua, 'mobile') !== false
        || strpos($ua, 'windows ce') !== false
        || strpos($ua, 'epoc') !== false
        || strpos($ua, 'opera mini') !== false
        || strpos($ua, 'nitro') !== false
        || strpos($ua, 'j2me') !== false
        || strpos($ua, 'midp-') !== false
        || strpos($ua, 'cldc-') !== false
        || strpos($ua, 'netfront') !== false
        || strpos($ua, 'mot') !== false
        || strpos($ua, 'up.browser') !== false
        || strpos($ua, 'up.link') !== false
        || strpos($ua, 'audiovox') !== false
        || strpos($ua, 'blackberry') !== false
        || strpos($ua, 'ericsson,') !== false
        || strpos($ua, 'panasonic') !== false
        || strpos($ua, 'philips') !== false
        || strpos($ua, 'sanyo') !== false
        || strpos($ua, 'sharp') !== false
        || strpos($ua, 'sie-') !== false
        || strpos($ua, 'portalmmm') !== false
        || strpos($ua, 'blazer') !== false
        || strpos($ua, 'avantgo') !== false
        || strpos($ua, 'danger') !== false
        || strpos($ua, 'palm') !== false
        || strpos($ua, 'series60') !== false
        || strpos($ua, 'palmsource') !== false
        || strpos($ua, 'pocketpc') !== false
        || strpos($ua, 'smartphone') !== false
        || strpos($ua, 'rover') !== false
        || strpos($ua, 'ipaq') !== false
        || strpos($ua, 'au-mic,') !== false
        || strpos($ua, 'alcatel') !== false
        || strpos($ua, 'ericy') !== false
        || strpos($ua, 'up.link') !== false
        || strpos($ua, 'vodafone/') !== false
        || strpos($ua, 'wap1.') !== false
        || strpos($ua, 'wap2.') !== false;

	return $isMobile;
}
?>