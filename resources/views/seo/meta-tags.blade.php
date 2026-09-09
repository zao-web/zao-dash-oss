{{-- SEO Page Meta Tags for Attribution Tracking --}}
{{-- Include these in WordPress page head for SEO pages --}}
@if(isset($seoPage))
<meta name="zao:is_seo_page" content="true">
<meta name="zao:page_type" content="{{ $seoPage->page_type ?? 'service_page' }}">
<meta name="zao:target_keyword" content="{{ $seoPage->target_keyword ?? '' }}">
<meta name="zao:playbook" content="{{ $seoPage->playbook ?? '' }}">
<meta name="zao:page_id" content="{{ $seoPage->id }}">
@endif
