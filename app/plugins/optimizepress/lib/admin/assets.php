<?php
class OptimizePress_Admin_Assets {
	
	function __construct(){
		if(defined('OP_POST_PAGE') && OP_POST_PAGE === true){
			add_action('admin_init',array($this,'init'));
			add_filter(OP_SN.'-script-localize',array($this,'localize'));
		}
		if(defined('OP_LIVEEDITOR')){
			$this->liveeditor_init();
			add_filter(OP_SN.'-script-localize',array($this,'localize'));
		}
	}

	function init(){
		add_action('admin_print_styles', array($this,'print_scripts'));
		add_action('admin_footer', array($this,'dialog_output'));
		add_action('media_buttons',array($this,'media_button'));
	}

	function liveeditor_init(){
		add_action('wp_print_styles', array($this,'print_scripts'));
		add_action('admin_footer', array($this,'dialog_output'));
		add_action('media_buttons',array($this,'media_button'));
	}
	
	function media_button(){
		echo (!(isset($GLOBALS['op_disable_asset_link']) && $GLOBALS['op_disable_asset_link'] === true) ? '
			<a onclick="return false;" title="'.__('Add Element',OP_SN).'" id="op-insert-asset" href="#"  class="button add-op-element">
				<span class="op-element-buttons-icon"></span>Add Element
			</a>
		' : '');
	}
	
	function add_language($lang_array){
		$mce_locale = ( '' == get_locale() ) ? 'en' : strtolower( substr(get_locale(), 0, 2) );
		$lang_array['OptimizePressAssets'] = OP_JS_PATH.'assets/langs/'. $mce_locale . '.php';
		return $lang_array;
	}
	
	function add_plugin($plugin_array){
		$plugin_array['OptimizePressAssets'] = OP_JS.'assets/plugin.js';
		return $plugin_array;
	}
	
	function register_plugin($buttons){
		array_push($buttons, 'separator', 'optimizepress_assets_button');
		return $buttons;
	}
	
	function print_scripts(){
		wp_enqueue_style( OP_SN.'-admin-assets',  OP_CSS.'assets.css', array(OP_SN.'-admin-common',OP_SN.'-fancybox'), '2011-10-05' );
		wp_enqueue_script( OP_SN.'-base64', OP_JS.'jquery/jquery.base64.min.js', array('jquery'));
		wp_enqueue_script( OP_SN.'-asset-browser', OP_JS.'assets/dialog.js', array(OP_SN.'-admin-common',OP_SN.'-base64',OP_SN.'-fancybox'), '2011-10-05', 1 );
		wp_enqueue_script('jquery-ui-slider');
	}
	
	function localize($js){
		$js = array_merge($js,array(
			'core_assets_url' => OP_JS.'assets/core/',
			'addon_assets_url' => OP_ASSETS_URL.'addon/',
			'theme_assets_url' => (defined('OP_THEME_URL')?OP_THEME_URL.'assets/':(defined('OP_PAGE_URL')?OP_PAGE_URL.'assets/':''))
		));
		return $js;
	}
	
	function dialog_output(){
		echo op_tpl('assets/dialog');
	}
}
new OptimizePress_Admin_Assets();