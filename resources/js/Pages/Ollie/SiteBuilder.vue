<script setup lang="ts">
import AppLayout from '@/Layouts/AppLayout.vue';
import Modal from '@/Components/Modal.vue';
import { ref, computed, reactive, watch, onMounted, onUnmounted } from 'vue';
import Echo from 'laravel-echo';

// Helper to decode HTML entities (safety net for backend-encoded strings)
const decodeHtml = (html: string): string => {
    if (!html) return html;
    const txt = document.createElement('textarea');
    txt.innerHTML = html;
    return txt.value;
};

const truncate = (str: string, length: number): string => {
    if (!str) return '';
    return str.length > length ? str.slice(0, length) + '...' : str;
};

const formatKey = (key: string): string => {
    return key.replace(/_/g, ' ').replace(/\b\w/g, c => c.toUpperCase());
};
import { router, usePage } from '@inertiajs/vue3';

// Get CSRF token for fetch requests
const getCsrfToken = (): string => {
    const page = usePage();
    return (page.props as any).csrf_token ||
        document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '';
};

// Helper for authenticated fetch requests
const authFetch = async (url: string, options: RequestInit = {}): Promise<Response> => {
    const headers = new Headers(options.headers);
    headers.set('X-CSRF-TOKEN', getCsrfToken());
    headers.set('Accept', 'application/json');

    // Only set Content-Type for non-FormData bodies
    if (options.body && !(options.body instanceof FormData)) {
        headers.set('Content-Type', 'application/json');
    }

    return fetch(url, { ...options, headers, credentials: 'same-origin' });
};

interface OllieProject {
    id: string;
    name: string;
    slug: string;
    type: 'new' | 'migration' | 'redesign';
    environment: 'staging' | 'production';
    status: string;
    created_at: string;
}

interface Pattern {
    slug: string;
    name: string;
    category: string;
    description: string;
}

const props = defineProps<{
    projects?: OllieProject[];
    patterns?: Pattern[];
}>();

// Wizard state
const currentStep = ref(1);
const totalSteps = 5;

// Step 1: Brief Upload
const briefSource = ref<'upload' | 'paste' | 'url' | 'github'>('upload');
const briefFile = ref<File | null>(null);
const briefText = ref('');
const briefUrl = ref('');
const githubUrl = ref('');
const isAnalyzing = ref(false);

// Step 2: Extracted Requirements
const extractedData = reactive({
    project_name: '',
    project_type: 'new' as 'new' | 'migration' | 'redesign',
    colors: {
        primary: '#5344F4',
        secondary: '#1E1E26',
        accent: '#e9e7ff',
    },
    pages: ['home', 'about', 'services', 'contact'],
    migration_url: '',
    integrations: [] as string[],
    custom_requirements: [] as string[],
});

// Store actual page URLs/paths for analysis (maps display name -> {path, url})
const pageUrlMap = reactive<Record<string, { path: string; url: string }>>({
    home: { path: '/', url: '' },
});

// Step 3: Design System
const designConfig = reactive({
    base_style: 'default' as 'default' | 'agency' | 'creator' | 'startup' | 'studio',
    heading_font: 'primary' as 'primary' | 'expanded' | 'condensed' | 'narrow',
    spacing_scale: 'default' as 'compact' | 'default' | 'spacious',
    button_radius: '5px',
});

// Step 4: Page Selection
const selectedPatterns = reactive<Record<string, string[]>>({
    home: [],
    about: [],
    services: [],
    contact: [],
});

// Step 5: Execution
const executionStatus = reactive({
    brief_analyzer: 'pending' as 'pending' | 'running' | 'completed' | 'failed',
    design_system: 'pending' as 'pending' | 'running' | 'completed' | 'failed',
    page_builder: 'pending' as 'pending' | 'running' | 'completed' | 'failed',
    migration: 'pending' as 'pending' | 'running' | 'completed' | 'failed',
    block_creator: 'pending' as 'pending' | 'running' | 'completed' | 'failed',
});

const stagingUrl = ref('');
const isExecuting = ref(false);

// Page Analysis State
const analysisBatchId = ref<string | null>(null);
const isAnalyzingPages = ref(false);
const pageAnalysisStatus = reactive<Record<string, 'pending' | 'analyzing' | 'completed' | 'failed'>>({});
const pageAnalysisResults = reactive<Record<string, {
    sections: Array<{ type: string; confidence: number; content?: Record<string, any> }>;
    suggested_patterns: string[];
}>>({});
const expandedContentPreviews = reactive<Record<string, boolean>>({});
const analysisProgress = reactive({
    completed: 0,
    total: 0,
    message: '',
});

// Map section types to pattern categories
const sectionToPatternMap: Record<string, string> = {
    hero: 'heroes',
    features: 'features',
    testimonials: 'testimonials',
    pricing: 'pricing',
    cta: 'ctas',
    blog: 'blog',
    team: 'team',
    contact: 'contact',
    gallery: 'gallery',
    faq: 'faq',
};

// Computed
const canProceed = computed(() => {
    switch (currentStep.value) {
        case 1:
            return briefFile.value || briefText.value || briefUrl.value || githubUrl.value;
        case 2:
            return extractedData.project_name && extractedData.colors.primary;
        case 3:
            return true;
        case 4:
            return Object.values(selectedPatterns).some(p => p.length > 0);
        case 5:
            return true;
        default:
            return false;
    }
});

const stepTitle = computed(() => {
    const titles: Record<number, string> = {
        1: 'Upload Brief',
        2: 'Review Requirements',
        3: 'Design System',
        4: 'Page Layout',
        5: 'Build Site',
    };
    return titles[currentStep.value] || '';
});

// Methods
const handleFileUpload = (event: Event) => {
    const target = event.target as HTMLInputElement;
    if (target.files?.length) {
        briefFile.value = target.files[0];
    }
};

const repoAnalysis = ref<any>(null);
const siteAnalysis = ref<any>(null);

const analyzeBrief = async () => {
    isAnalyzing.value = true;

    try {
        if (briefSource.value === 'github') {
            // Analyze GitHub repository
            const repoResponse = await authFetch('/api/ollie/analyze-repo', {
                method: 'POST',
                body: JSON.stringify({ repo_url: githubUrl.value, depth: 'deep' }),
            });
            const repoData = await repoResponse.json();

            if (repoData.result) {
                repoAnalysis.value = repoData.result;
                extractedData.project_name = repoData.result.repository?.repo || 'Migration Project';
                extractedData.project_type = 'migration';
                extractedData.migration_url = githubUrl.value;

                // Extract suggested pages from components
                const suggestedPages = repoData.result.wordpress_mapping?.blocks
                    ?.filter((b: any) => b.suggested_block?.includes('ollie/'))
                    ?.map((b: any) => b.source.toLowerCase()) || [];
                if (suggestedPages.length > 0) {
                    extractedData.pages = [...new Set(['home', ...suggestedPages])];
                }
            }
            currentStep.value = 2;
            return;
        }

        const formData = new FormData();

        if (briefSource.value === 'upload' && briefFile.value) {
            formData.append('file', briefFile.value);
        } else if (briefSource.value === 'paste') {
            formData.append('content', briefText.value);
        } else if (briefSource.value === 'url') {
            formData.append('url', briefUrl.value);
            // For URL, analyze the site with enhanced extraction
            const siteResponse = await authFetch('/api/ollie/analyze-site', {
                method: 'POST',
                body: JSON.stringify({ url: briefUrl.value, depth: 'shallow' }),
            });
            const siteData = await siteResponse.json();

            if (siteData.result) {
                siteAnalysis.value = siteData.result;
                extractedData.migration_url = briefUrl.value;
                extractedData.project_type = 'migration';

                // Extract site name
                if (siteData.result.site_name?.name) {
                    extractedData.project_name = siteData.result.site_name.name;
                }

                // Extract brand colors
                if (siteData.result.brand_colors?.suggested?.primary) {
                    extractedData.colors.primary = siteData.result.brand_colors.suggested.primary;
                }
                if (siteData.result.brand_colors?.suggested?.secondary) {
                    extractedData.colors.secondary = siteData.result.brand_colors.suggested.secondary;
                }
                if (siteData.result.brand_colors?.suggested?.accent) {
                    extractedData.colors.accent = siteData.result.brand_colors.suggested.accent;
                }

                // Import pages from sitemap or navigation
                if (siteData.result.sitemap?.pages?.length > 0) {
                    const sitemapPages = siteData.result.sitemap.pages.slice(0, 20);
                    const pageNames: string[] = [];

                    // Build pageUrlMap with actual URLs from sitemap
                    sitemapPages.forEach((p: any) => {
                        const displayName = p.name?.toLowerCase() || p.path?.replace(/^\//, '').split('/')[0] || 'home';
                        const path = p.path || '/';
                        const url = p.url || '';

                        if (displayName && !pageNames.includes(displayName)) {
                            pageNames.push(displayName);
                            pageUrlMap[displayName] = { path, url };
                        }
                    });

                    // Ensure home is always first
                    if (!pageUrlMap['home']) {
                        pageUrlMap['home'] = { path: '/', url: briefUrl.value };
                    }

                    extractedData.pages = [...new Set(['home', ...pageNames.filter((p: string) => p && p !== 'home')])];
                }
            }
        }

        // Parse the brief
        const response = await authFetch('/api/ollie/parse-brief', {
            method: 'POST',
            body: formData,
        });
        const data = await response.json();

        if (data.result) {
            extractedData.project_name = data.result.project_name || 'Untitled Project';
            if (data.result.colors?.primary) {
                extractedData.colors.primary = data.result.colors.primary;
            }
            if (data.result.colors?.secondary) {
                extractedData.colors.secondary = data.result.colors.secondary;
            }
            if (data.result.colors?.accent) {
                extractedData.colors.accent = data.result.colors.accent;
            }
            if (data.result.pages) {
                extractedData.pages = data.result.pages;
            }
        }

        currentStep.value = 2;
    } catch (error) {
        console.error('Failed to analyze brief:', error);
    } finally {
        isAnalyzing.value = false;
    }
};

const nextStep = () => {
    if (currentStep.value < totalSteps) {
        currentStep.value++;
    }
};

const prevStep = () => {
    if (currentStep.value > 1) {
        currentStep.value--;
    }
};

const projectId = ref('');

const startBuild = async () => {
    isExecuting.value = true;

    try {
        // Step 1: Create the project
        executionStatus.brief_analyzer = 'running';
        const createResponse = await authFetch('/api/ollie/projects', {
            method: 'POST',
            body: JSON.stringify({
                name: extractedData.project_name,
                type: extractedData.project_type,
                environment: 'staging',
            }),
        });
        const createData = await createResponse.json();
        if (!createData.result?.project?.id) throw new Error('Failed to create project');
        projectId.value = createData.result.project.id;
        executionStatus.brief_analyzer = 'completed';

        // Step 2: Generate theme.json
        executionStatus.design_system = 'running';
        await authFetch('/api/ollie/generate-theme', {
            method: 'POST',
            body: JSON.stringify({
                project_id: projectId.value,
                colors: extractedData.colors,
                base_style: designConfig.base_style,
                typography: { heading_font: designConfig.heading_font },
            }),
        });
        executionStatus.design_system = 'completed';

        // Step 3: Build pages
        executionStatus.page_builder = 'running';
        for (const pageName of extractedData.pages) {
            const patterns = selectedPatterns[pageName] || [];
            if (patterns.length > 0) {
                await authFetch('/api/ollie/compose-page', {
                    method: 'POST',
                    body: JSON.stringify({
                        project_id: projectId.value,
                        page_title: pageName.charAt(0).toUpperCase() + pageName.slice(1),
                        page_slug: pageName,
                        patterns: patterns,
                    }),
                });
            }
        }
        executionStatus.page_builder = 'completed';

        // Step 4: Migration (if applicable)
        if (extractedData.project_type === 'migration' && extractedData.migration_url) {
            executionStatus.migration = 'running';
            // Migration would be handled by the agent
            await new Promise(resolve => setTimeout(resolve, 1000));
            executionStatus.migration = 'completed';
        }

        stagingUrl.value = `https://${extractedData.project_name.toLowerCase().replace(/\s+/g, '-')}.staging.example.com`;
    } catch (error) {
        console.error('Build failed:', error);
        // Mark current running step as failed
        Object.keys(executionStatus).forEach(key => {
            if (executionStatus[key as keyof typeof executionStatus] === 'running') {
                executionStatus[key as keyof typeof executionStatus] = 'failed';
            }
        });
    } finally {
        isExecuting.value = false;
    }
};

// Pattern categories for selection
const patternCategories = [
    { id: 'heroes', name: 'Heroes', icon: '🏠' },
    { id: 'features', name: 'Features', icon: '✨' },
    { id: 'testimonials', name: 'Testimonials', icon: '💬' },
    { id: 'pricing', name: 'Pricing', icon: '💰' },
    { id: 'ctas', name: 'Call to Action', icon: '📣' },
    { id: 'blog', name: 'Blog', icon: '📝' },
    { id: 'team', name: 'Team', icon: '👥' },
    { id: 'contact', name: 'Contact', icon: '📧' },
    { id: 'gallery', name: 'Gallery', icon: '🖼️' },
    { id: 'faq', name: 'FAQ', icon: '❓' },
];

// Start page analysis for all pages
const startPageAnalysis = async () => {
    if (!extractedData.migration_url || extractedData.pages.length === 0) return;

    isAnalyzingPages.value = true;

    // Initialize status for all pages
    extractedData.pages.forEach(page => {
        pageAnalysisStatus[page] = 'pending';
    });

    // Prepare pages array with actual URLs from pageUrlMap
    const pagesPayload = extractedData.pages.map(page => {
        const urlData = pageUrlMap[page];
        return {
            name: page,
            path: urlData?.path || (page === 'home' ? '/' : `/${page.toLowerCase().replace(/\s+/g, '-')}`),
            url: urlData?.url || '',
        };
    });

    try {
        const response = await authFetch('/api/ollie/analyze-pages', {
            method: 'POST',
            body: JSON.stringify({
                base_url: extractedData.migration_url,
                pages: pagesPayload,
            }),
        });
        const data = await response.json();

        if (data.batch_id) {
            analysisBatchId.value = data.batch_id;
            analysisProgress.total = data.total_pages || extractedData.pages.length;

            // Mark all pages as analyzing
            extractedData.pages.forEach(page => {
                pageAnalysisStatus[page] = 'analyzing';
            });

            // Poll for results (fallback if Echo not connected)
            pollForResults(data.batch_id);
        }
    } catch (error) {
        console.error('Failed to start page analysis:', error);
        isAnalyzingPages.value = false;
    }
};

// Poll for results (fallback mechanism)
let pollInterval: ReturnType<typeof setInterval> | null = null;
const pollForResults = async (batchId: string) => {
    pollInterval = setInterval(async () => {
        try {
            const response = await authFetch(`/api/ollie/analyze-pages/${batchId}`);
            const data = await response.json();

            if (data.status === 'completed' && data.results) {
                clearInterval(pollInterval!);
                pollInterval = null;
                processAnalysisResults(data.results);
            } else if (data.progress) {
                analysisProgress.completed = data.progress.completed || 0;
                analysisProgress.message = data.progress.message || '';
            }
        } catch (error) {
            console.error('Failed to poll for results:', error);
        }
    }, 2000);
};

// Process analysis results and pre-select patterns
const processAnalysisResults = (results: Record<string, any>) => {
    isAnalyzingPages.value = false;

    Object.entries(results).forEach(([pageName, result]) => {
        if (result.success && result.sections) {
            pageAnalysisStatus[pageName] = 'completed';
            pageAnalysisResults[pageName] = {
                sections: result.sections,
                suggested_patterns: result.suggested_patterns || [],
            };

            // Pre-select patterns based on detected sections
            const detectedPatterns: string[] = [];
            result.sections.forEach((section: { type: string; confidence: number }) => {
                const patternId = sectionToPatternMap[section.type];
                if (patternId && section.confidence >= 0.6 && !detectedPatterns.includes(patternId)) {
                    detectedPatterns.push(patternId);
                }
            });

            // Initialize and set selected patterns
            if (!selectedPatterns[pageName]) {
                selectedPatterns[pageName] = [];
            }
            selectedPatterns[pageName] = detectedPatterns;
        } else {
            pageAnalysisStatus[pageName] = 'failed';
        }
    });
};

const hasExtractedContent = (page: string): boolean => {
    const sections = pageAnalysisResults[page]?.sections || [];
    return sections.some(s => s.content && Object.keys(s.content).length > 0);
};

const toggleContentPreview = (page: string): void => {
    expandedContentPreviews[page] = !expandedContentPreviews[page];
};

// Watch for step changes to trigger analysis
watch(currentStep, (newStep, oldStep) => {
    if (newStep === 4 && oldStep !== 4) {
        // Only start analysis if we have a migration URL and haven't already analyzed
        if (extractedData.migration_url && !analysisBatchId.value) {
            startPageAnalysis();
        }
    }
});

// Echo listener setup
let echoChannel: any = null;
onMounted(() => {
    // Try to set up Echo listener if available
    if (typeof window !== 'undefined' && (window as any).Echo) {
        echoChannel = (window as any).Echo.channel('ollie-analysis');
        echoChannel.listen('.page.analysis.progress', (event: any) => {
            if (event.batch_id === analysisBatchId.value) {
                analysisProgress.completed = event.completed;
                analysisProgress.total = event.total;
                analysisProgress.message = event.message;

                // Update individual page status
                if (event.current_page) {
                    pageAnalysisStatus[event.current_page] = event.page_status === 'completed' ? 'completed' : 'failed';
                }

                // If all complete, fetch full results
                if (event.completed === event.total) {
                    fetchFinalResults();
                }
            }
        });
    }
});

const fetchFinalResults = async () => {
    if (!analysisBatchId.value) return;

    try {
        const response = await authFetch(`/api/ollie/analyze-pages/${analysisBatchId.value}`);
        const data = await response.json();
        if (data.results) {
            processAnalysisResults(data.results);
        }
    } catch (error) {
        console.error('Failed to fetch final results:', error);
    }
};

onUnmounted(() => {
    if (pollInterval) {
        clearInterval(pollInterval);
    }
    if (echoChannel) {
        echoChannel.stopListening('.page.analysis.progress');
    }
});

const styleOptions = [
    { value: 'default', label: 'Default', description: 'Clean, modern purple theme' },
    { value: 'agency', label: 'Agency', description: 'Bold greens with uppercase headings' },
    { value: 'creator', label: 'Creator', description: 'Warm, playful aesthetics' },
    { value: 'startup', label: 'Startup', description: 'Modern, bold colors' },
    { value: 'studio', label: 'Studio', description: 'Minimal, refined' },
];

const fontOptions = [
    { value: 'primary', label: 'Mona Sans', description: 'Standard width, balanced', stretch: 'normal', widthLabel: '100%' },
    { value: 'expanded', label: 'Mona Sans Expanded', description: 'Wide, impactful headers', stretch: 'expanded', widthLabel: '125%' },
    { value: 'condensed', label: 'Mona Sans Condensed', description: 'Compact, space-efficient', stretch: 'condensed', widthLabel: '75%' },
    { value: 'narrow', label: 'Mona Sans Narrow', description: 'Tall, editorial style', stretch: 'narrow', widthLabel: '62.5%' },
];
</script>

<template>
    <AppLayout title="Ollie Site Builder">
        <div class="site-builder">
            <!-- Progress Steps -->
            <div class="wizard-progress">
                <div
                    v-for="step in totalSteps"
                    :key="step"
                    :class="['step', { active: step === currentStep, completed: step < currentStep }]"
                >
                    <div class="step-number">
                        <svg v-if="step < currentStep" class="w-4 h-4" fill="currentColor" viewBox="0 0 20 20">
                            <path fill-rule="evenodd" d="M16.707 5.293a1 1 0 010 1.414l-8 8a1 1 0 01-1.414 0l-4-4a1 1 0 011.414-1.414L8 12.586l7.293-7.293a1 1 0 011.414 0z" clip-rule="evenodd" />
                        </svg>
                        <span v-else>{{ step }}</span>
                    </div>
                    <span class="step-label">{{ ['Brief', 'Review', 'Design', 'Pages', 'Build'][step - 1] }}</span>
                </div>
            </div>

            <!-- Step Content -->
            <div class="wizard-content">
                <h2 class="step-title">{{ stepTitle }}</h2>

                <!-- Step 1: Upload Brief -->
                <div v-if="currentStep === 1" class="step-content">
                    <div class="source-tabs">
                        <button
                            :class="['tab', { active: briefSource === 'upload' }]"
                            @click="briefSource = 'upload'"
                        >
                            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 16a4 4 0 01-.88-7.903A5 5 0 1115.9 6L16 6a5 5 0 011 9.9M15 13l-3-3m0 0l-3 3m3-3v12" />
                            </svg>
                            Upload PDF
                        </button>
                        <button
                            :class="['tab', { active: briefSource === 'paste' }]"
                            @click="briefSource = 'paste'"
                        >
                            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z" />
                            </svg>
                            Paste Text
                        </button>
                        <button
                            :class="['tab', { active: briefSource === 'url' }]"
                            @click="briefSource = 'url'"
                        >
                            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13.828 10.172a4 4 0 00-5.656 0l-4 4a4 4 0 105.656 5.656l1.102-1.101m-.758-4.899a4 4 0 005.656 0l4-4a4 4 0 00-5.656-5.656l-1.1 1.1" />
                            </svg>
                            Site URL
                        </button>
                        <button
                            :class="['tab', { active: briefSource === 'github' }]"
                            @click="briefSource = 'github'"
                        >
                            <svg class="w-5 h-5" fill="currentColor" viewBox="0 0 24 24">
                                <path fill-rule="evenodd" d="M12 2C6.477 2 2 6.484 2 12.017c0 4.425 2.865 8.18 6.839 9.504.5.092.682-.217.682-.483 0-.237-.008-.868-.013-1.703-2.782.605-3.369-1.343-3.369-1.343-.454-1.158-1.11-1.466-1.11-1.466-.908-.62.069-.608.069-.608 1.003.07 1.531 1.032 1.531 1.032.892 1.53 2.341 1.088 2.91.832.092-.647.35-1.088.636-1.338-2.22-.253-4.555-1.113-4.555-4.951 0-1.093.39-1.988 1.029-2.688-.103-.253-.446-1.272.098-2.65 0 0 .84-.27 2.75 1.026A9.564 9.564 0 0112 6.844c.85.004 1.705.115 2.504.337 1.909-1.296 2.747-1.027 2.747-1.027.546 1.379.202 2.398.1 2.651.64.7 1.028 1.595 1.028 2.688 0 3.848-2.339 4.695-4.566 4.943.359.309.678.92.678 1.855 0 1.338-.012 2.419-.012 2.747 0 .268.18.58.688.482A10.019 10.019 0 0022 12.017C22 6.484 17.522 2 12 2z" clip-rule="evenodd" />
                            </svg>
                            GitHub Repo
                        </button>
                    </div>

                    <div class="source-content">
                        <div v-if="briefSource === 'upload'" class="upload-zone">
                            <input
                                type="file"
                                accept=".pdf,.doc,.docx"
                                class="file-input"
                                @change="handleFileUpload"
                            />
                            <div class="upload-icon">
                                <svg class="w-12 h-12" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M7 16a4 4 0 01-.88-7.903A5 5 0 1115.9 6L16 6a5 5 0 011 9.9M15 13l-3-3m0 0l-3 3m3-3v12" />
                                </svg>
                            </div>
                            <p class="upload-text">
                                <span v-if="briefFile">{{ briefFile.name }}</span>
                                <span v-else>Drop your brief here or click to upload</span>
                            </p>
                            <p class="upload-hint">PDF, DOC, DOCX up to 10MB</p>
                        </div>

                        <div v-if="briefSource === 'paste'" class="paste-zone">
                            <textarea
                                v-model="briefText"
                                class="paste-textarea"
                                placeholder="Paste your product brief, brand guidelines, or project requirements here..."
                                rows="12"
                            ></textarea>
                        </div>

                        <div v-if="briefSource === 'url'" class="url-zone">
                            <label class="input-label">Existing Site URL</label>
                            <input
                                v-model="briefUrl"
                                type="url"
                                class="url-input"
                                placeholder="https://current-site.com"
                            />
                            <p class="input-hint">Enter the URL of the site you want to migrate or redesign</p>
                        </div>

                        <div v-if="briefSource === 'github'" class="url-zone">
                            <label class="input-label">GitHub Repository URL</label>
                            <input
                                v-model="githubUrl"
                                type="url"
                                class="url-input"
                                placeholder="https://github.com/owner/repo"
                            />
                            <p class="input-hint">Analyze any codebase - WordPress, Joomla, Drupal, Laravel, React, or custom</p>
                        </div>
                    </div>
                </div>

                <!-- Step 2: Review Requirements -->
                <div v-if="currentStep === 2" class="step-content">
                    <!-- Site Analysis Summary -->
                    <div v-if="siteAnalysis" class="analysis-summary">
                        <div class="analysis-header">
                            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z" />
                            </svg>
                            <span>Site Analysis Complete</span>
                        </div>
                        <div class="analysis-details">
                            <div class="analysis-item">
                                <span class="analysis-label">Platform</span>
                                <span class="analysis-value">{{ siteAnalysis.platform?.type || 'Unknown' }}</span>
                            </div>
                            <div class="analysis-item">
                                <span class="analysis-label">Pages Found</span>
                                <span class="analysis-value">
                                    {{ siteAnalysis.sitemap?.pages?.length || 0 }}
                                    <span class="analysis-source">({{ siteAnalysis.sitemap?.source || 'unknown' }})</span>
                                </span>
                            </div>
                            <div v-if="siteAnalysis.site_name?.source" class="analysis-item">
                                <span class="analysis-label">Name Source</span>
                                <span class="analysis-value">{{ siteAnalysis.site_name.source }}</span>
                            </div>
                        </div>
                    </div>

                    <div class="form-grid">
                        <div class="form-group">
                            <label class="input-label">Project Name</label>
                            <input
                                v-model="extractedData.project_name"
                                type="text"
                                class="form-input"
                                placeholder="Acme Corp Website"
                            />
                            <span v-if="siteAnalysis?.site_name?.confidence" class="input-hint">
                                Detected with {{ siteAnalysis.site_name.confidence }}% confidence
                            </span>
                        </div>

                        <div class="form-group">
                            <label class="input-label">Project Type</label>
                            <select v-model="extractedData.project_type" class="form-select">
                                <option value="new">New Build</option>
                                <option value="migration">Migration</option>
                                <option value="redesign">Redesign</option>
                            </select>
                        </div>
                    </div>

                    <div class="color-section">
                        <label class="input-label">Brand Colors</label>
                        <div class="color-grid">
                            <div class="color-picker">
                                <input
                                    v-model="extractedData.colors.primary"
                                    type="color"
                                    class="color-input"
                                />
                                <span class="color-label">Primary</span>
                            </div>
                            <div class="color-picker">
                                <input
                                    v-model="extractedData.colors.secondary"
                                    type="color"
                                    class="color-input"
                                />
                                <span class="color-label">Secondary</span>
                            </div>
                            <div class="color-picker">
                                <input
                                    v-model="extractedData.colors.accent"
                                    type="color"
                                    class="color-input"
                                />
                                <span class="color-label">Accent</span>
                            </div>
                        </div>
                    </div>

                    <div class="pages-section">
                        <label class="input-label">Pages to Create</label>
                        <div class="pages-chips">
                            <span
                                v-for="page in extractedData.pages"
                                :key="page"
                                class="page-chip"
                            >
                                {{ decodeHtml(page) }}
                                <button class="chip-remove" @click="extractedData.pages = extractedData.pages.filter(p => p !== page)">×</button>
                            </span>
                            <input
                                type="text"
                                class="page-input"
                                placeholder="Add page..."
                                @keyup.enter="(e) => {
                                    const input = e.target as HTMLInputElement;
                                    if (input.value.trim()) {
                                        extractedData.pages.push(input.value.trim().toLowerCase());
                                        input.value = '';
                                    }
                                }"
                            />
                        </div>
                    </div>
                </div>

                <!-- Step 3: Design System -->
                <div v-if="currentStep === 3" class="step-content">
                    <div class="design-section">
                        <label class="input-label">Base Style</label>
                        <div class="style-grid">
                            <button
                                v-for="style in styleOptions"
                                :key="style.value"
                                :class="['style-option', { active: designConfig.base_style === style.value }]"
                                @click="designConfig.base_style = style.value"
                            >
                                <span class="style-name">{{ style.label }}</span>
                                <span class="style-desc">{{ style.description }}</span>
                            </button>
                        </div>
                    </div>

                    <div class="design-section">
                        <label class="input-label">Heading Font</label>
                        <div class="font-grid">
                            <button
                                v-for="font in fontOptions"
                                :key="font.value"
                                :class="['font-option', { active: designConfig.heading_font === font.value }]"
                                @click="designConfig.heading_font = font.value"
                            >
                                <div class="font-width-preview">
                                    <div :class="['width-bar', `width-${font.stretch}`]"></div>
                                </div>
                                <span class="font-name">{{ font.label }}</span>
                                <span class="font-desc">{{ font.description }}</span>
                                <span class="font-width-label">{{ font.widthLabel }}</span>
                            </button>
                        </div>
                    </div>

                    <div class="form-grid">
                        <div class="form-group">
                            <label class="input-label">Spacing Scale</label>
                            <select v-model="designConfig.spacing_scale" class="form-select">
                                <option value="compact">Compact</option>
                                <option value="default">Default</option>
                                <option value="spacious">Spacious</option>
                            </select>
                        </div>
                        <div class="form-group">
                            <label class="input-label">Button Radius</label>
                            <select v-model="designConfig.button_radius" class="form-select">
                                <option value="0">Square</option>
                                <option value="5px">Slightly Rounded</option>
                                <option value="10px">Rounded</option>
                                <option value="9999px">Pill</option>
                            </select>
                        </div>
                    </div>
                </div>

                <!-- Step 4: Page Layout -->
                <div v-if="currentStep === 4" class="step-content">
                    <p class="step-description">
                        <template v-if="isAnalyzingPages">
                            Analyzing pages to detect content sections...
                            <span class="analysis-progress-text">
                                ({{ analysisProgress.completed }}/{{ analysisProgress.total }})
                            </span>
                        </template>
                        <template v-else-if="Object.keys(pageAnalysisResults).length > 0">
                            AI detected sections and pre-selected patterns. Adjust as needed.
                        </template>
                        <template v-else>
                            Select patterns for each page. The Ollie Page Builder will compose these into cohesive layouts.
                        </template>
                    </p>

                    <!-- Analysis Progress Bar -->
                    <div v-if="isAnalyzingPages" class="analysis-progress-bar">
                        <div
                            class="progress-fill"
                            :style="{ width: analysisProgress.total > 0 ? `${(analysisProgress.completed / analysisProgress.total) * 100}%` : '0%' }"
                        ></div>
                    </div>

                    <div class="page-builder">
                        <div
                            v-for="page in extractedData.pages"
                            :key="page"
                            class="page-section"
                            :class="{
                                'is-analyzing': pageAnalysisStatus[page] === 'analyzing',
                                'is-completed': pageAnalysisStatus[page] === 'completed',
                                'is-failed': pageAnalysisStatus[page] === 'failed',
                            }"
                        >
                            <div class="page-header">
                                <h3 class="page-name">{{ decodeHtml(page) }}</h3>
                                <div class="page-status">
                                    <svg v-if="pageAnalysisStatus[page] === 'analyzing'" class="spinner-icon" viewBox="0 0 24 24">
                                        <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4" fill="none"></circle>
                                        <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                                    </svg>
                                    <span v-else-if="pageAnalysisStatus[page] === 'completed'" class="status-badge status-success">AI Detected</span>
                                    <span v-else-if="pageAnalysisStatus[page] === 'failed'" class="status-badge status-error">Analysis Failed</span>
                                </div>
                            </div>

                            <!-- Detected Sections Preview -->
                            <div v-if="pageAnalysisResults[page]?.sections?.length" class="detected-sections-wrapper">
                                <div class="detected-sections">
                                    <span
                                        v-for="section in pageAnalysisResults[page].sections.slice(0, 4)"
                                        :key="section.type"
                                        class="section-tag"
                                        :class="{ 'has-content': section.content && Object.keys(section.content).length > 0 }"
                                        :title="`${Math.round(section.confidence)}% confidence`"
                                    >
                                        {{ section.type }}
                                    </span>
                                    <span v-if="pageAnalysisResults[page].sections.length > 4" class="section-tag more">
                                        +{{ pageAnalysisResults[page].sections.length - 4 }} more
                                    </span>
                                </div>
                                <button
                                    v-if="hasExtractedContent(page)"
                                    class="content-toggle"
                                    @click="toggleContentPreview(page)"
                                >
                                    {{ expandedContentPreviews[page] ? 'Hide' : 'Preview' }} Content
                                </button>
                            </div>

                            <!-- Extracted Content Preview -->
                            <div v-if="expandedContentPreviews[page]" class="content-preview">
                                <div
                                    v-for="section in pageAnalysisResults[page].sections.filter(s => s.content && Object.keys(s.content).length > 0)"
                                    :key="section.type"
                                    class="content-section"
                                >
                                    <h4 class="content-section-title">{{ section.type }}</h4>
                                    <div class="content-items">
                                        <template v-for="(value, key) in section.content" :key="key">
                                            <div v-if="typeof value === 'string'" class="content-item">
                                                <span class="content-label">{{ formatKey(key) }}:</span>
                                                <span class="content-value">{{ truncate(value, 100) }}</span>
                                            </div>
                                            <div v-else-if="Array.isArray(value) && value.length > 0" class="content-item content-item-list">
                                                <span class="content-label">{{ formatKey(key) }} ({{ value.length }}):</span>
                                                <ul class="content-list">
                                                    <li v-for="(item, idx) in value.slice(0, 3)" :key="idx">
                                                        {{ typeof item === 'string' ? truncate(item, 60) : (item.title || item.name || item.quote || item.text || JSON.stringify(item).slice(0, 50)) }}
                                                    </li>
                                                    <li v-if="value.length > 3" class="more-items">+{{ value.length - 3 }} more</li>
                                                </ul>
                                            </div>
                                        </template>
                                    </div>
                                </div>
                            </div>

                            <div class="pattern-categories">
                                <button
                                    v-for="cat in patternCategories"
                                    :key="cat.id"
                                    class="pattern-cat"
                                    :class="{
                                        selected: selectedPatterns[page]?.includes(cat.id),
                                        'ai-suggested': pageAnalysisResults[page]?.sections?.some(s => sectionToPatternMap[s.type] === cat.id && s.confidence >= 0.6),
                                    }"
                                    @click="
                                        selectedPatterns[page] = selectedPatterns[page] || [];
                                        const idx = selectedPatterns[page].indexOf(cat.id);
                                        if (idx > -1) {
                                            selectedPatterns[page].splice(idx, 1);
                                        } else {
                                            selectedPatterns[page].push(cat.id);
                                        }
                                    "
                                >
                                    <span class="cat-icon">{{ cat.icon }}</span>
                                    <span class="cat-name">{{ cat.name }}</span>
                                </button>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Step 5: Build -->
                <div v-if="currentStep === 5" class="step-content">
                    <div class="execution-panel">
                        <div class="agent-status">
                            <div
                                v-for="(status, agent) in executionStatus"
                                :key="agent"
                                class="agent-row"
                            >
                                <div :class="['status-indicator', status]">
                                    <svg v-if="status === 'completed'" class="w-4 h-4" fill="currentColor" viewBox="0 0 20 20">
                                        <path fill-rule="evenodd" d="M16.707 5.293a1 1 0 010 1.414l-8 8a1 1 0 01-1.414 0l-4-4a1 1 0 011.414-1.414L8 12.586l7.293-7.293a1 1 0 011.414 0z" clip-rule="evenodd" />
                                    </svg>
                                    <svg v-else-if="status === 'running'" class="w-4 h-4 animate-spin" fill="none" viewBox="0 0 24 24">
                                        <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                                        <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                                    </svg>
                                    <div v-else class="pending-dot"></div>
                                </div>
                                <span class="agent-name">{{ agent.replace('_', ' ') }}</span>
                                <span :class="['agent-status-text', status]">{{ status }}</span>
                            </div>
                        </div>

                        <div v-if="stagingUrl" class="staging-preview">
                            <h3>Site Ready!</h3>
                            <a :href="stagingUrl" target="_blank" class="staging-link">
                                {{ stagingUrl }}
                                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 6H6a2 2 0 00-2 2v10a2 2 0 002 2h10a2 2 0 002-2v-4M14 4h6m0 0v6m0-6L10 14" />
                                </svg>
                            </a>
                        </div>

                        <button
                            v-if="!isExecuting && !stagingUrl"
                            class="btn btn-primary btn-lg"
                            @click="startBuild"
                        >
                            Start Building
                        </button>
                    </div>
                </div>
            </div>

            <!-- Navigation -->
            <div class="wizard-nav">
                <button
                    v-if="currentStep > 1"
                    class="btn btn-secondary"
                    @click="prevStep"
                >
                    Back
                </button>
                <div class="flex-1"></div>
                <button
                    v-if="currentStep === 1"
                    class="btn btn-primary"
                    :disabled="!canProceed || isAnalyzing"
                    @click="analyzeBrief"
                >
                    {{ isAnalyzing ? 'Analyzing...' : 'Analyze Brief' }}
                </button>
                <button
                    v-else-if="currentStep < totalSteps"
                    class="btn btn-primary"
                    :disabled="!canProceed"
                    @click="nextStep"
                >
                    Continue
                </button>
            </div>
        </div>
    </AppLayout>
</template>

<style scoped>
.site-builder {
    max-width: 900px;
    margin: 0 auto;
    padding: 2rem;
}

/* Progress Steps */
.wizard-progress {
    display: flex;
    justify-content: space-between;
    margin-bottom: 3rem;
    position: relative;
}

.wizard-progress::before {
    content: '';
    position: absolute;
    top: 16px;
    left: 40px;
    right: 40px;
    height: 2px;
    background: var(--color-border-default);
}

.step {
    display: flex;
    flex-direction: column;
    align-items: center;
    gap: 0.5rem;
    z-index: 1;
}

.step-number {
    width: 32px;
    height: 32px;
    border-radius: 50%;
    background: var(--color-bg-elevated);
    border: 2px solid var(--color-border-default);
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 0.875rem;
    font-weight: 600;
    color: var(--color-text-tertiary);
}

.step.active .step-number {
    background: var(--color-accent);
    border-color: var(--color-accent);
    color: white;
}

.step.completed .step-number {
    background: var(--color-status-green);
    border-color: var(--color-status-green);
    color: white;
}

.step-label {
    font-size: 0.75rem;
    color: var(--color-text-tertiary);
}

.step.active .step-label,
.step.completed .step-label {
    color: var(--color-text-primary);
}

/* Content */
.wizard-content {
    background: var(--color-bg-surface);
    border: 1px solid var(--color-border-default);
    border-radius: 12px;
    padding: 2rem;
    min-height: 400px;
}

.step-title {
    font-size: 1.5rem;
    font-weight: 600;
    margin-bottom: 1.5rem;
    color: var(--color-text-primary);
}

.step-description {
    color: var(--color-text-secondary);
    margin-bottom: 1.5rem;
}

/* Step 1: Source Tabs */
.source-tabs {
    display: flex;
    gap: 0.5rem;
    margin-bottom: 1.5rem;
}

.tab {
    flex: 1;
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 0.5rem;
    padding: 0.75rem 1rem;
    background: var(--color-bg-elevated);
    border: 1px solid var(--color-border-default);
    border-radius: 8px;
    color: var(--color-text-secondary);
    cursor: pointer;
    transition: all 0.15s ease;
}

.tab:hover {
    border-color: var(--color-border-hover);
}

.tab.active {
    background: var(--color-accent);
    border-color: var(--color-accent);
    color: white;
}

/* Upload Zone */
.upload-zone {
    border: 2px dashed var(--color-border-default);
    border-radius: 12px;
    padding: 3rem;
    text-align: center;
    position: relative;
    transition: all 0.15s ease;
}

.upload-zone:hover {
    border-color: var(--color-accent);
}

.file-input {
    position: absolute;
    inset: 0;
    opacity: 0;
    cursor: pointer;
}

.upload-icon {
    color: var(--color-text-tertiary);
    margin-bottom: 1rem;
    display: flex;
    justify-content: center;
}

.upload-text {
    color: var(--color-text-primary);
    font-weight: 500;
}

.upload-hint {
    color: var(--color-text-tertiary);
    font-size: 0.875rem;
    margin-top: 0.5rem;
}

/* Paste Zone */
.paste-textarea {
    width: 100%;
    padding: 1rem;
    background: var(--color-bg-elevated);
    border: 1px solid var(--color-border-default);
    border-radius: 8px;
    color: var(--color-text-primary);
    font-size: 0.875rem;
    line-height: 1.6;
    resize: vertical;
}

.paste-textarea:focus {
    outline: none;
    border-color: var(--color-accent);
}

/* URL Zone */
.url-input {
    width: 100%;
    padding: 0.75rem 1rem;
    background: var(--color-bg-elevated);
    border: 1px solid var(--color-border-default);
    border-radius: 8px;
    color: var(--color-text-primary);
    font-size: 1rem;
}

.url-input:focus {
    outline: none;
    border-color: var(--color-accent);
}

/* Analysis Summary */
.analysis-summary {
    background: rgba(34, 197, 94, 0.08);
    border: 1px solid rgba(34, 197, 94, 0.2);
    border-radius: 10px;
    padding: 1rem 1.25rem;
    margin-bottom: 1.5rem;
}

.analysis-header {
    display: flex;
    align-items: center;
    gap: 0.5rem;
    color: var(--color-status-green);
    font-weight: 600;
    margin-bottom: 0.75rem;
}

.analysis-details {
    display: flex;
    flex-wrap: wrap;
    gap: 1.5rem;
}

.analysis-item {
    display: flex;
    flex-direction: column;
    gap: 0.125rem;
}

.analysis-label {
    font-size: 0.6875rem;
    text-transform: uppercase;
    letter-spacing: 0.05em;
    color: var(--color-text-tertiary);
}

.analysis-value {
    font-size: 0.875rem;
    color: var(--color-text-primary);
    font-weight: 500;
    text-transform: capitalize;
}

.analysis-source {
    font-weight: 400;
    color: var(--color-text-tertiary);
    font-size: 0.75rem;
}

/* Form Elements */
.form-grid {
    display: grid;
    grid-template-columns: repeat(2, 1fr);
    gap: 1.5rem;
    margin-bottom: 1.5rem;
}

.form-group {
    display: flex;
    flex-direction: column;
    gap: 0.5rem;
}

.input-label {
    font-size: 0.875rem;
    font-weight: 500;
    color: var(--color-text-secondary);
}

.input-hint {
    font-size: 0.75rem;
    color: var(--color-text-tertiary);
    margin-top: 0.25rem;
}

.form-input,
.form-select {
    padding: 0.75rem 1rem;
    background: var(--color-bg-elevated);
    border: 1px solid var(--color-border-default);
    border-radius: 8px;
    color: var(--color-text-primary);
    font-size: 0.875rem;
}

.form-input:focus,
.form-select:focus {
    outline: none;
    border-color: var(--color-accent);
}

/* Colors */
.color-section {
    margin-bottom: 1.5rem;
}

.color-grid {
    display: flex;
    gap: 1.5rem;
    margin-top: 0.5rem;
}

.color-picker {
    display: flex;
    flex-direction: column;
    align-items: center;
    gap: 0.5rem;
}

.color-input {
    width: 60px;
    height: 60px;
    border: 2px solid var(--color-border-default);
    border-radius: 8px;
    cursor: pointer;
}

.color-label {
    font-size: 0.75rem;
    color: var(--color-text-tertiary);
}

/* Pages */
.pages-chips {
    display: flex;
    flex-wrap: wrap;
    gap: 0.5rem;
    margin-top: 0.5rem;
}

.page-chip {
    display: flex;
    align-items: center;
    gap: 0.25rem;
    padding: 0.375rem 0.75rem;
    background: var(--color-accent-subtle, rgba(59, 130, 246, 0.1));
    color: var(--color-accent);
    border-radius: 9999px;
    font-size: 0.875rem;
}

.chip-remove {
    background: none;
    border: none;
    color: inherit;
    cursor: pointer;
    font-size: 1rem;
    line-height: 1;
    opacity: 0.7;
}

.chip-remove:hover {
    opacity: 1;
}

.page-input {
    padding: 0.375rem 0.75rem;
    background: transparent;
    border: 1px dashed var(--color-border-default);
    border-radius: 9999px;
    color: var(--color-text-primary);
    font-size: 0.875rem;
    width: 120px;
}

/* Design System */
.design-section {
    margin-bottom: 2rem;
}

.style-grid {
    display: grid;
    grid-template-columns: repeat(3, 1fr);
    gap: 1rem;
    margin-top: 0.75rem;
}

.style-option {
    padding: 1rem;
    background: var(--color-bg-elevated);
    border: 2px solid var(--color-border-default);
    border-radius: 8px;
    text-align: left;
    cursor: pointer;
    transition: all 0.15s ease;
}

.style-option:hover {
    border-color: var(--color-border-hover);
}

.style-option.active {
    border-color: var(--color-accent);
    background: rgba(59, 130, 246, 0.05);
}

.style-name {
    display: block;
    font-weight: 600;
    color: var(--color-text-primary);
}

.style-desc {
    display: block;
    font-size: 0.75rem;
    color: var(--color-text-tertiary);
    margin-top: 0.25rem;
}

.font-grid {
    display: flex;
    gap: 1rem;
    margin-top: 0.75rem;
}

.font-option {
    flex: 1;
    display: flex;
    flex-direction: column;
    align-items: center;
    padding: 1rem;
    background: var(--color-bg-elevated);
    border: 2px solid var(--color-border-default);
    border-radius: 8px;
    text-align: center;
    cursor: pointer;
    transition: all 0.15s ease;
}

.font-option:hover {
    border-color: var(--color-border-hover);
}

.font-option.active {
    border-color: var(--color-accent);
}

.font-width-preview {
    height: 32px;
    display: flex;
    align-items: center;
    justify-content: center;
    margin-bottom: 0.5rem;
}

.width-bar {
    height: 24px;
    background: var(--color-accent);
    border-radius: 4px;
    transition: width 0.2s ease;
}

.width-normal {
    width: 50px;
}

.width-expanded {
    width: 70px;
}

.width-condensed {
    width: 35px;
}

.width-narrow {
    width: 25px;
}

.font-name {
    display: block;
    font-size: 0.8125rem;
    font-weight: 600;
    color: var(--color-text-primary);
    margin-bottom: 0.25rem;
}

.font-desc {
    display: block;
    font-size: 0.6875rem;
    color: var(--color-text-tertiary);
    margin-bottom: 0.375rem;
}

.font-width-label {
    display: block;
    font-size: 0.625rem;
    font-family: var(--font-mono);
    color: var(--color-text-muted);
}

/* Page Builder */
.analysis-progress-text {
    color: var(--color-accent);
    font-weight: 500;
}

.analysis-progress-bar {
    height: 4px;
    background: var(--color-bg-elevated);
    border-radius: 2px;
    margin-bottom: 1.5rem;
    overflow: hidden;
}

.progress-fill {
    height: 100%;
    background: var(--color-accent);
    border-radius: 2px;
    transition: width 0.3s ease;
}

.page-section {
    padding: 1rem;
    background: var(--color-bg-elevated);
    border-radius: 8px;
    margin-bottom: 1rem;
    border: 2px solid transparent;
    transition: border-color 0.2s ease;
}

.page-section.is-analyzing {
    border-color: var(--color-accent);
}

.page-section.is-completed {
    border-color: var(--color-status-green);
}

.page-section.is-failed {
    border-color: var(--color-status-red);
}

.page-header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    margin-bottom: 0.5rem;
}

.page-name {
    font-size: 1rem;
    font-weight: 600;
    color: var(--color-text-primary);
    text-transform: capitalize;
    margin: 0;
}

.page-status {
    display: flex;
    align-items: center;
}

.spinner-icon {
    width: 18px;
    height: 18px;
    color: var(--color-accent);
    animation: spin 1s linear infinite;
}

.status-badge {
    font-size: 0.6875rem;
    font-weight: 600;
    text-transform: uppercase;
    letter-spacing: 0.03em;
    padding: 0.25rem 0.5rem;
    border-radius: 4px;
}

.status-success {
    background: rgba(34, 197, 94, 0.15);
    color: var(--color-status-green);
}

.status-error {
    background: rgba(239, 68, 68, 0.15);
    color: var(--color-status-red);
}

.detected-sections {
    display: flex;
    flex-wrap: wrap;
    gap: 0.375rem;
    margin-bottom: 0.75rem;
}

.section-tag {
    font-size: 0.6875rem;
    font-weight: 500;
    text-transform: capitalize;
    padding: 0.125rem 0.5rem;
    background: var(--color-bg-surface);
    border: 1px solid var(--color-border-default);
    border-radius: 4px;
    color: var(--color-text-secondary);
}

.section-tag.more {
    color: var(--color-text-tertiary);
    font-style: italic;
}

.section-tag.has-content {
    background: rgba(34, 197, 94, 0.1);
    border-color: rgba(34, 197, 94, 0.3);
    color: var(--color-status-green);
}

.detected-sections-wrapper {
    display: flex;
    align-items: center;
    justify-content: space-between;
    margin-bottom: 0.75rem;
}

.content-toggle {
    font-size: 0.6875rem;
    padding: 0.25rem 0.5rem;
    background: var(--color-bg-surface);
    border: 1px solid var(--color-border-default);
    border-radius: 4px;
    color: var(--color-text-secondary);
    cursor: pointer;
    transition: all 0.15s ease;
}

.content-toggle:hover {
    background: var(--color-bg-elevated);
    border-color: var(--color-accent);
    color: var(--color-accent);
}

.content-preview {
    background: var(--color-bg-surface);
    border: 1px solid var(--color-border-default);
    border-radius: 6px;
    padding: 0.75rem;
    margin-bottom: 0.75rem;
    max-height: 300px;
    overflow-y: auto;
}

.content-section {
    margin-bottom: 0.75rem;
    padding-bottom: 0.75rem;
    border-bottom: 1px solid var(--color-border-default);
}

.content-section:last-child {
    margin-bottom: 0;
    padding-bottom: 0;
    border-bottom: none;
}

.content-section-title {
    font-size: 0.75rem;
    font-weight: 600;
    text-transform: capitalize;
    color: var(--color-accent);
    margin: 0 0 0.5rem 0;
}

.content-items {
    display: flex;
    flex-direction: column;
    gap: 0.375rem;
}

.content-item {
    font-size: 0.6875rem;
    line-height: 1.4;
}

.content-label {
    font-weight: 500;
    color: var(--color-text-secondary);
}

.content-value {
    color: var(--color-text-tertiary);
    margin-left: 0.25rem;
}

.content-item-list .content-list {
    margin: 0.25rem 0 0 1rem;
    padding: 0;
    list-style: disc;
}

.content-list li {
    font-size: 0.625rem;
    color: var(--color-text-tertiary);
    margin-bottom: 0.125rem;
}

.content-list .more-items {
    color: var(--color-text-quaternary);
    font-style: italic;
}

.pattern-categories {
    display: flex;
    flex-wrap: wrap;
    gap: 0.5rem;
}

.pattern-cat {
    display: flex;
    align-items: center;
    gap: 0.5rem;
    padding: 0.5rem 0.75rem;
    background: var(--color-bg-surface);
    border: 1px solid var(--color-border-default);
    border-radius: 6px;
    cursor: pointer;
    transition: all 0.15s ease;
}

.pattern-cat:hover {
    border-color: var(--color-border-hover);
}

.pattern-cat.selected {
    background: var(--color-accent);
    border-color: var(--color-accent);
    color: white;
}

.pattern-cat.ai-suggested:not(.selected) {
    border-color: var(--color-status-green);
    background: rgba(34, 197, 94, 0.08);
}

.pattern-cat.ai-suggested:not(.selected)::after {
    content: '✓';
    position: absolute;
    top: -4px;
    right: -4px;
    width: 14px;
    height: 14px;
    background: var(--color-status-green);
    color: white;
    font-size: 8px;
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
}

.pattern-cat {
    position: relative;
}

.cat-icon {
    font-size: 1rem;
}

.cat-name {
    font-size: 0.8125rem;
}

/* Execution */
.execution-panel {
    text-align: center;
}

.agent-status {
    max-width: 400px;
    margin: 0 auto 2rem;
}

.agent-row {
    display: flex;
    align-items: center;
    gap: 1rem;
    padding: 0.75rem 1rem;
    background: var(--color-bg-elevated);
    border-radius: 8px;
    margin-bottom: 0.5rem;
}

.status-indicator {
    width: 24px;
    height: 24px;
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
}

.status-indicator.pending {
    background: var(--color-bg-surface);
}

.status-indicator.running {
    background: var(--color-accent);
    color: white;
}

.status-indicator.completed {
    background: var(--color-status-green);
    color: white;
}

.pending-dot {
    width: 8px;
    height: 8px;
    background: var(--color-text-tertiary);
    border-radius: 50%;
}

.agent-name {
    flex: 1;
    text-transform: capitalize;
    text-align: left;
    color: var(--color-text-primary);
}

.agent-status-text {
    font-size: 0.75rem;
    text-transform: uppercase;
}

.agent-status-text.pending {
    color: var(--color-text-tertiary);
}

.agent-status-text.running {
    color: var(--color-accent);
}

.agent-status-text.completed {
    color: var(--color-status-green);
}

.staging-preview {
    margin-top: 2rem;
    padding: 1.5rem;
    background: rgba(34, 197, 94, 0.1);
    border-radius: 12px;
}

.staging-preview h3 {
    color: var(--color-status-green);
    margin-bottom: 0.5rem;
}

.staging-link {
    display: inline-flex;
    align-items: center;
    gap: 0.5rem;
    color: var(--color-accent);
    text-decoration: none;
    font-weight: 500;
}

.staging-link:hover {
    text-decoration: underline;
}

/* Navigation */
.wizard-nav {
    display: flex;
    align-items: center;
    margin-top: 2rem;
    padding-top: 1.5rem;
    border-top: 1px solid var(--color-border-subtle);
}

/* Buttons */
.btn {
    padding: 0.625rem 1.25rem;
    font-size: 0.875rem;
    font-weight: 500;
    border-radius: 8px;
    cursor: pointer;
    transition: all 0.15s ease;
    border: none;
}

.btn:disabled {
    opacity: 0.5;
    cursor: not-allowed;
}

.btn-primary {
    background: var(--color-accent);
    color: white;
}

.btn-primary:hover:not(:disabled) {
    background: var(--color-accent-hover);
}

.btn-secondary {
    background: var(--color-bg-elevated);
    color: var(--color-text-primary);
    border: 1px solid var(--color-border-default);
}

.btn-secondary:hover:not(:disabled) {
    background: var(--color-bg-surface);
    border-color: var(--color-border-hover);
}

.btn-lg {
    padding: 0.875rem 2rem;
    font-size: 1rem;
}

.flex-1 {
    flex: 1;
}

@keyframes spin {
    from { transform: rotate(0deg); }
    to { transform: rotate(360deg); }
}

.animate-spin {
    animation: spin 1s linear infinite;
}

@media (max-width: 768px) {
    .form-grid,
    .style-grid {
        grid-template-columns: 1fr;
    }

    .font-grid {
        flex-wrap: wrap;
    }

    .source-tabs {
        flex-direction: column;
    }
}
</style>
