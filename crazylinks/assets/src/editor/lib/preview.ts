/**
 * Preview document helpers: the bridge script injected into the sandboxed preview iframe.
 */

export const PREVIEW_DEBOUNCE_MS = 400;

/**
 * The bridge injected into the preview document.
 *
 * @param scrollY Scroll position to restore.
 */
export function bridgeScript( scrollY: number ): string {
	return `<script>(function(){
var y=${ Math.max( 0, Math.round( scrollY ) ) };
function post(m){m.source='crazylinks-preview';parent.postMessage(m,'*');}
addEventListener('load',function(){scrollTo(0,y);});
document.addEventListener('DOMContentLoaded',function(){scrollTo(0,y);});
var t;addEventListener('scroll',function(){clearTimeout(t);t=setTimeout(function(){post({type:'scroll',y:scrollY});},80);},{passive:true});
document.addEventListener('click',function(e){
var a=e.target.closest&&e.target.closest('a[href]');
if(a&&a.getAttribute('href').charAt(0)!=='#'){e.preventDefault();}
var b=e.target.closest&&e.target.closest('[id^="cl-b-"]');
if(b){post({type:'select',id:b.id.slice(5)});}
},true);
document.addEventListener('submit',function(e){e.preventDefault();},true);
addEventListener('message',function(e){
var d=e.data;if(!d||d.source!=='crazylinks-editor')return;
var old=document.querySelectorAll('.cl-preview-hl');for(var i=0;i<old.length;i++){old[i].classList.remove('cl-preview-hl');}
if(d.type==='highlight'&&d.id){var el=document.getElementById('cl-b-'+d.id);if(el){el.classList.add('cl-preview-hl');var r=el.getBoundingClientRect();if(r.top<0||r.bottom>innerHeight){el.scrollIntoView({block:'center',behavior:matchMedia('(prefers-reduced-motion: reduce)').matches?'auto':'smooth'});}}}
});
})();</script><style>.cl-preview-hl{outline:2px dashed #3858e9;outline-offset:4px;border-radius:4px}</style>`;
}

/**
 * Full preview document from the endpoint response, with the bridge injected.
 *
 * @param res          Response.
 * @param res.html     Fragment.
 * @param res.document Full document.
 * @param scrollY      Scroll position.
 */
export function buildSrcDoc(
	res: { html: string; document?: string },
	scrollY: number
): string {
	const bridge = bridgeScript( scrollY );
	if ( res.document && res.document.includes( '</body>' ) ) {
		return res.document.replace( '</body>', bridge + '</body>' );
	}
	return `<!DOCTYPE html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"></head><body class="cl-body"><main id="cl-main" class="cl-main">${ res.html }</main>${ bridge }</body></html>`;
}
