<script setup lang="ts">
import { computed } from 'vue';
import { marked } from 'marked';

const props = defineProps<{
    content: string;
}>();

// Configure marked with custom renderer
const renderer = new marked.Renderer();

// Add IDs to headings for anchor links
renderer.heading = ({ text, depth }) => {
    const slug = text
        .toLowerCase()
        .replace(/[^\w\s-]/g, '')
        .replace(/\s+/g, '-');
    return `<h${depth} id="${slug}">${text}</h${depth}>`;
};

// Add classes to tables
renderer.table = (token) => {
    const header = token.header.map(h =>
        `<th>${h.text}</th>`
    ).join('');

    const body = token.rows.map(row =>
        `<tr>${row.map(cell => `<td>${cell.text}</td>`).join('')}</tr>`
    ).join('');

    return `<div class="table-wrapper"><table><thead><tr>${header}</tr></thead><tbody>${body}</tbody></table></div>`;
};

// Style code blocks
renderer.code = ({ text, lang }) => {
    const language = lang || 'text';
    return `<pre class="code-block" data-language="${language}"><code class="language-${language}">${text}</code></pre>`;
};

// External links open in new tab
renderer.link = ({ href, text }) => {
    const isExternal = href.startsWith('http');
    const attrs = isExternal ? ' target="_blank" rel="noopener noreferrer"' : '';
    return `<a href="${href}"${attrs}>${text}</a>`;
};

marked.setOptions({
    renderer,
    gfm: true,
    breaks: true,
});

const renderedContent = computed(() => {
    return marked(props.content);
});
</script>

<template>
    <div class="markdown-body" v-html="renderedContent"></div>
</template>

<style>
.markdown-body {
    color: var(--color-text-primary);
    font-size: 0.9375rem;
    line-height: 1.7;
}

.markdown-body h1 {
    font-size: 2rem;
    font-weight: 700;
    color: var(--color-text-primary);
    margin: 0 0 1.5rem 0;
    padding-bottom: 0.75rem;
    border-bottom: 1px solid var(--color-border-subtle);
}

.markdown-body h2 {
    font-size: 1.5rem;
    font-weight: 600;
    color: var(--color-text-primary);
    margin: 2.5rem 0 1rem 0;
    padding-top: 1rem;
}

.markdown-body h3 {
    font-size: 1.25rem;
    font-weight: 600;
    color: var(--color-text-primary);
    margin: 2rem 0 0.75rem 0;
}

.markdown-body h4 {
    font-size: 1rem;
    font-weight: 600;
    color: var(--color-text-secondary);
    margin: 1.5rem 0 0.5rem 0;
}

.markdown-body p {
    margin: 0 0 1rem 0;
    color: var(--color-text-secondary);
}

.markdown-body a {
    color: var(--color-accent);
    text-decoration: none;
    transition: color 0.15s;
}

.markdown-body a:hover {
    color: var(--color-accent-hover);
    text-decoration: underline;
}

.markdown-body strong {
    font-weight: 600;
    color: var(--color-text-primary);
}

.markdown-body em {
    font-style: italic;
}

.markdown-body ul,
.markdown-body ol {
    margin: 0 0 1rem 0;
    padding-left: 1.5rem;
    color: var(--color-text-secondary);
}

.markdown-body li {
    margin-bottom: 0.375rem;
}

.markdown-body li > ul,
.markdown-body li > ol {
    margin: 0.375rem 0 0 0;
}

.markdown-body blockquote {
    margin: 1.5rem 0;
    padding: 1rem 1.5rem;
    background: var(--color-bg-tertiary);
    border-left: 4px solid var(--color-accent);
    border-radius: 0 8px 8px 0;
}

.markdown-body blockquote p {
    margin: 0;
    color: var(--color-text-secondary);
}

.markdown-body hr {
    margin: 2rem 0;
    border: none;
    border-top: 1px solid var(--color-border-subtle);
}

/* Inline code */
.markdown-body code {
    font-family: var(--font-mono);
    font-size: 0.875em;
    padding: 0.125rem 0.375rem;
    background: var(--color-bg-tertiary);
    border: 1px solid var(--color-border-subtle);
    border-radius: 4px;
    color: var(--color-accent);
}

/* Code blocks */
.markdown-body .code-block {
    margin: 1.5rem 0;
    padding: 1rem 1.25rem;
    background: var(--color-bg-primary);
    border: 1px solid var(--color-border-subtle);
    border-radius: 8px;
    overflow-x: auto;
    position: relative;
}

.markdown-body .code-block::before {
    content: attr(data-language);
    position: absolute;
    top: 0.5rem;
    right: 0.75rem;
    font-family: var(--font-mono);
    font-size: 0.6875rem;
    text-transform: uppercase;
    color: var(--color-text-quaternary);
    letter-spacing: 0.05em;
}

.markdown-body .code-block code {
    display: block;
    padding: 0;
    background: none;
    border: none;
    border-radius: 0;
    color: var(--color-text-secondary);
    font-size: 0.8125rem;
    line-height: 1.6;
    white-space: pre;
}

/* Tables */
.markdown-body .table-wrapper {
    margin: 1.5rem 0;
    overflow-x: auto;
    border: 1px solid var(--color-border-subtle);
    border-radius: 8px;
}

.markdown-body table {
    width: 100%;
    border-collapse: collapse;
    font-size: 0.875rem;
}

.markdown-body th {
    padding: 0.75rem 1rem;
    text-align: left;
    font-weight: 600;
    color: var(--color-text-primary);
    background: var(--color-bg-tertiary);
    border-bottom: 1px solid var(--color-border-subtle);
}

.markdown-body td {
    padding: 0.75rem 1rem;
    color: var(--color-text-secondary);
    border-bottom: 1px solid var(--color-border-subtle);
}

.markdown-body tr:last-child td {
    border-bottom: none;
}

.markdown-body tr:hover td {
    background: var(--color-bg-tertiary);
}

/* Images */
.markdown-body img {
    max-width: 100%;
    height: auto;
    border-radius: 8px;
    margin: 1.5rem 0;
    border: 1px solid var(--color-border-subtle);
}

/* Task lists */
.markdown-body input[type="checkbox"] {
    margin-right: 0.5rem;
    accent-color: var(--color-accent);
}

/* Horizontal scrolling for wide content */
.markdown-body pre,
.markdown-body .table-wrapper {
    -webkit-overflow-scrolling: touch;
}

/* Keyboard shortcut styling */
.markdown-body kbd {
    font-family: var(--font-mono);
    font-size: 0.75rem;
    padding: 0.125rem 0.375rem;
    background: var(--color-bg-primary);
    border: 1px solid var(--color-border-default);
    border-radius: 4px;
    box-shadow: 0 1px 0 var(--color-border-default);
    color: var(--color-text-secondary);
}
</style>
