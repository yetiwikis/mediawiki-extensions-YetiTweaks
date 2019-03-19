<?php

class GloopTweaksUtils {
	/**
	 * Adds an extra link to the footer, should only be called when
   * hooking onto SkinTemplateOutputPageBeforeExec
   * 
   * @param String $name - The name of the footer link
   * @param String $url - Interface message for the URL to use. Passed to Skin::makeInternalOrExternalUrl
   * @param String $msg - Interface message for the text to show
	 * @param SkinTemplate &$skin
	 * @param QuickTemplate &$template
	 */
	public static function addFooterLink ( $name, $url, $msg, &$skin, &$template ) {
    $dest = Skin::makeInternalOrExternalUrl(
      $skin->msg( $url )->inContentLanguage()->text() );
    $link = Html::element(
      'a',
      [ 'href' => $dest ],
      $skin->msg( $msg )->text()
    );
    $template->set( $name, $link );
    $template->data['footerlinks']['places'][] = $name;
  }
}

?>