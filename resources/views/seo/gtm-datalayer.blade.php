{{-- GTM Data Layer for SEO Attribution Tracking --}}
{{-- Include this script in WordPress theme header before GTM container --}}
<script>
window.dataLayer = window.dataLayer || [];

(function() {
    'use strict';

    // Initialize tracking state from session storage
    const trackingState = {
        firstTouch: JSON.parse(sessionStorage.getItem('zao_first_touch') || 'null'),
        pagesViewed: parseInt(sessionStorage.getItem('zao_pages_viewed') || '0') + 1,
        sessionStart: parseInt(sessionStorage.getItem('zao_session_start') || Date.now()),
        maxScrollDepth: 0,
    };

    // Set first touch if not exists
    if (!trackingState.firstTouch) {
        const urlParams = new URLSearchParams(window.location.search);
        trackingState.firstTouch = {
            url: window.location.href,
            keyword: urlParams.get('keyword') || '',
            source: urlParams.get('utm_source') || (document.referrer ? new URL(document.referrer).hostname : 'direct'),
            medium: urlParams.get('utm_medium') || 'organic',
            campaign: urlParams.get('utm_campaign') || '',
            timestamp: Date.now(),
        };
        sessionStorage.setItem('zao_first_touch', JSON.stringify(trackingState.firstTouch));
    }

    // Update session storage
    sessionStorage.setItem('zao_pages_viewed', trackingState.pagesViewed.toString());
    if (!sessionStorage.getItem('zao_session_start')) {
        sessionStorage.setItem('zao_session_start', trackingState.sessionStart.toString());
    }

    // Get page metadata from data attributes or meta tags
    const getPageMeta = (name) => {
        const meta = document.querySelector(`meta[name="${name}"]`);
        return meta ? meta.content : '';
    };

    // Push initial page view with enhanced data
    dataLayer.push({
        event: 'page_view_enhanced',
        page_type: getPageMeta('zao:page_type') || 'standard',
        is_seo_page: getPageMeta('zao:is_seo_page') === 'true',
        target_keyword: getPageMeta('zao:target_keyword') || '',
        playbook: getPageMeta('zao:playbook') || '',
        pages_in_session: trackingState.pagesViewed,
        time_in_session: Math.floor((Date.now() - trackingState.sessionStart) / 1000),
        first_touch_source: trackingState.firstTouch.source,
        first_touch_medium: trackingState.firstTouch.medium,
        first_touch_campaign: trackingState.firstTouch.campaign,
    });

    // Scroll depth tracking
    const scrollDepthMarkers = [25, 50, 75, 100];
    const firedMarkers = [];

    function getScrollDepth() {
        const scrollTop = window.pageYOffset || document.documentElement.scrollTop;
        const docHeight = document.documentElement.scrollHeight - window.innerHeight;
        if (docHeight <= 0) return 100;
        return Math.round((scrollTop / docHeight) * 100);
    }

    function handleScroll() {
        const depth = getScrollDepth();
        trackingState.maxScrollDepth = Math.max(trackingState.maxScrollDepth, depth);
        sessionStorage.setItem('zao_max_scroll', trackingState.maxScrollDepth.toString());

        scrollDepthMarkers.forEach(marker => {
            if (depth >= marker && !firedMarkers.includes(marker)) {
                firedMarkers.push(marker);
                dataLayer.push({
                    event: 'scroll_depth',
                    scroll_depth: marker,
                    page_type: getPageMeta('zao:page_type') || 'standard',
                    target_keyword: getPageMeta('zao:target_keyword') || '',
                });
            }
        });
    }

    // Throttle scroll handler
    let scrollTimeout;
    window.addEventListener('scroll', function() {
        if (scrollTimeout) return;
        scrollTimeout = setTimeout(function() {
            scrollTimeout = null;
            handleScroll();
        }, 100);
    });

    // Time on page tracking
    const timeMarkers = [30, 60, 120, 300]; // seconds
    const firedTimeMarkers = [];
    const pageLoadTime = Date.now();

    setInterval(function() {
        const timeOnPage = Math.floor((Date.now() - pageLoadTime) / 1000);

        timeMarkers.forEach(marker => {
            if (timeOnPage >= marker && !firedTimeMarkers.includes(marker)) {
                firedTimeMarkers.push(marker);
                dataLayer.push({
                    event: 'time_on_page',
                    seconds: marker,
                    page_type: getPageMeta('zao:page_type') || 'standard',
                    target_keyword: getPageMeta('zao:target_keyword') || '',
                });
            }
        });
    }, 5000);

    // Get GA4 client ID when available
    function getGA4ClientId() {
        return new Promise((resolve) => {
            if (typeof gtag === 'function') {
                gtag('get', '{{ config("services.google.ga4_measurement_id", "G-XXXXXXXXXX") }}', 'client_id', (clientId) => {
                    resolve(clientId || '');
                });
            } else {
                // Fallback: try to get from cookie
                const match = document.cookie.match(/_ga=GA\d\.\d\.(\d+\.\d+)/);
                resolve(match ? match[1] : '');
            }
        });
    }

    // Expose attribution data for form submission
    window.zaoTracking = {
        getAttributionData: async function() {
            const ga4ClientId = await getGA4ClientId();
            const maxScroll = parseInt(sessionStorage.getItem('zao_max_scroll') || '0');

            return {
                first_touch_page_url: trackingState.firstTouch?.url || '',
                first_touch_keyword: trackingState.firstTouch?.keyword || '',
                first_touch_source: trackingState.firstTouch?.source || '',
                first_touch_medium: trackingState.firstTouch?.medium || '',
                first_touch_campaign: trackingState.firstTouch?.campaign || '',
                last_touch_page_url: window.location.href,
                pages_viewed: trackingState.pagesViewed,
                time_on_site_seconds: Math.floor((Date.now() - trackingState.sessionStart) / 1000),
                max_scroll_depth: Math.max(trackingState.maxScrollDepth, maxScroll),
                ga4_client_id: ga4ClientId,
                ga4_session_id: sessionStorage.getItem('zao_session_id') || '',
            };
        },

        // Helper to inject attribution into forms
        injectIntoForm: async function(formElement) {
            const data = await this.getAttributionData();
            Object.keys(data).forEach(key => {
                let input = formElement.querySelector(`input[name="${key}"]`);
                if (!input) {
                    input = document.createElement('input');
                    input.type = 'hidden';
                    input.name = key;
                    formElement.appendChild(input);
                }
                input.value = data[key];
            });
        },

        // Auto-inject into forms with data-zao-attribution attribute
        autoInjectForms: function() {
            document.querySelectorAll('form[data-zao-attribution]').forEach(form => {
                form.addEventListener('submit', async (e) => {
                    await this.injectIntoForm(form);
                });
            });
        }
    };

    // Auto-inject on DOM ready
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', () => window.zaoTracking.autoInjectForms());
    } else {
        window.zaoTracking.autoInjectForms();
    }

    // Track form submissions
    document.addEventListener('submit', function(e) {
        const form = e.target;
        if (form.tagName === 'FORM') {
            dataLayer.push({
                event: 'form_submitted',
                form_id: form.id || 'unknown',
                form_action: form.action,
                page_type: getPageMeta('zao:page_type') || 'standard',
                target_keyword: getPageMeta('zao:target_keyword') || '',
                first_touch_source: trackingState.firstTouch?.source || '',
            });
        }
    });

    // Track CTA clicks
    document.addEventListener('click', function(e) {
        const link = e.target.closest('a[data-zao-cta], button[data-zao-cta]');
        if (link) {
            dataLayer.push({
                event: 'cta_clicked',
                cta_text: link.textContent.trim().substring(0, 100),
                cta_type: link.dataset.zaoCta || 'default',
                page_type: getPageMeta('zao:page_type') || 'standard',
                target_keyword: getPageMeta('zao:target_keyword') || '',
            });
        }
    });
})();
</script>
