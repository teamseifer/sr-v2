/* ===== Configuração do funil SERASA LIMPA NOME =====
   Edite aqui os IDs de pixel. Valores dos produtos são os da oferta original. */
window.SR_CONFIG = {
  META_PIXEL_ID: '1601503185054241',
  UTMIFY_PIXEL_ID: 'COLOQUE_SEU_PIXEL_ID',   /* <<< preencha com o pixel da UTMify */
  ACORDO_CODE: 'X1KAL-10S--',
  CONTRACT: 'ACC.3289898302-2026',
  ACORDO_PRICE: 68.92,
  ACORDO_DOWNSELL_PRICE: 34.90
};
(function(){
  var C = window.SR_CONFIG;
  /* Meta Pixel */
  if (C.META_PIXEL_ID) {
    !function(f,b,e,v,n,t,s){if(f.fbq)return;n=f.fbq=function(){n.callMethod?n.callMethod.apply(n,arguments):n.queue.push(arguments)};if(!f._fbq)f._fbq=n;n.push=n;n.loaded=!0;n.version='2.0';n.queue=[];t=b.createElement(e);t.async=!0;t.src=v;s=b.getElementsByTagName(e)[0];s.parentNode.insertBefore(t,s)}(window,document,'script','https://connect.facebook.net/en_US/fbevents.js');
    fbq('init', C.META_PIXEL_ID); fbq('track', 'PageView');
  }
  /* UTMify */
  if (C.UTMIFY_PIXEL_ID && C.UTMIFY_PIXEL_ID !== 'COLOQUE_SEU_PIXEL_ID') {
    window.pixelId = C.UTMIFY_PIXEL_ID;
    var a = document.createElement('script'); a.async = true; a.defer = true; a.src = 'https://cdn.utmify.com.br/scripts/pixel/pixel.js'; document.head.appendChild(a);
  }
  // utms/latest.js agora e carregado pelo script oficial da UTMify na <head> de cada pagina (zip 80).
})();
