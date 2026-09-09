<?php

namespace App\Services\Ollie;

class OllieBlockGenerator
{
    private array $colors = [
        'primary' => '#5344F4',
        'secondary' => '#1E1E26',
        'accent' => '#e9e7ff',
    ];

    public function setColors(array $colors): self
    {
        $this->colors = array_merge($this->colors, $colors);

        return $this;
    }

    public function generateFromSection(array $section): string
    {
        return match ($section['type']) {
            'hero' => $this->generateHeroBlock($section['content']),
            'features' => $this->generateFeaturesBlock($section['content']),
            'testimonials' => $this->generateTestimonialsBlock($section['content']),
            'pricing' => $this->generatePricingBlock($section['content']),
            'team' => $this->generateTeamBlock($section['content']),
            'faq' => $this->generateFaqBlock($section['content']),
            'cta' => $this->generateCtaBlock($section['content']),
            'contact' => $this->generateContactBlock($section['content']),
            'gallery' => $this->generateGalleryBlock($section['content']),
            'blog' => $this->generateBlogBlock($section['content']),
            default => '',
        };
    }

    public function generatePageFromSections(array $sections): string
    {
        $blocks = [];

        foreach ($sections as $section) {
            $block = $this->generateFromSection($section);
            if (! empty($block)) {
                $blocks[] = $block;
            }
        }

        return implode("\n\n", $blocks);
    }

    private function generateHeroBlock(array $content): string
    {
        $headline = $this->escape($content['headline'] ?? 'Welcome');
        $subheadline = $this->escape($content['subheadline'] ?? '');
        $body = $this->escape($content['body'] ?? '');
        $bgImage = $content['background_image']['url'] ?? $content['image']['url'] ?? '';
        $buttons = $content['buttons'] ?? [];

        $buttonsHtml = '';
        if (! empty($buttons)) {
            $buttonBlocks = [];
            foreach (array_slice($buttons, 0, 2) as $i => $button) {
                $style = $i === 0 ? 'fill' : 'outline';
                $buttonBlocks[] = $this->generateButtonBlock($button, $style);
            }
            $buttonsHtml = $this->wrapInButtonsGroup($buttonBlocks);
        }

        $coverAttrs = [
            'align' => 'full',
            'style' => ['color' => ['text' => '#ffffff']],
        ];
        if ($bgImage) {
            $coverAttrs['url'] = $bgImage;
            $coverAttrs['dimRatio'] = 50;
        } else {
            $coverAttrs['overlayColor'] = 'primary';
        }

        return <<<BLOCK
<!-- wp:cover {$this->jsonAttr($coverAttrs)} -->
<div class="wp-block-cover alignfull">
<span aria-hidden="true" class="wp-block-cover__background has-primary-background-color has-background-dim-50 has-background-dim"></span>
<div class="wp-block-cover__inner-container">

<!-- wp:group {"layout":{"type":"constrained"}} -->
<div class="wp-block-group">

<!-- wp:heading {"textAlign":"center","level":1} -->
<h1 class="wp-block-heading has-text-align-center">{$headline}</h1>
<!-- /wp:heading -->

{$this->conditionalBlock($subheadline, fn ($t) => <<<SUB
<!-- wp:paragraph {"align":"center","fontSize":"large"} -->
<p class="has-text-align-center has-large-font-size">{$t}</p>
<!-- /wp:paragraph -->
SUB)}

{$this->conditionalBlock($body, fn ($t) => <<<BODY
<!-- wp:paragraph {"align":"center"} -->
<p class="has-text-align-center">{$t}</p>
<!-- /wp:paragraph -->
BODY)}

{$buttonsHtml}

</div>
<!-- /wp:group -->

</div>
</div>
<!-- /wp:cover -->
BLOCK;
    }

    private function generateFeaturesBlock(array $content): string
    {
        $sectionTitle = $this->escape($content['section_title'] ?? 'Features');
        $sectionIntro = $this->escape($content['section_intro'] ?? '');
        $features = $content['features'] ?? [];

        if (empty($features)) {
            return '';
        }

        $featureBlocks = [];
        foreach (array_slice($features, 0, 6) as $feature) {
            $icon = $feature['icon'] ?? '✨';
            $title = $this->escape($feature['title'] ?? '');
            $desc = $this->escape($feature['description'] ?? '');

            $featureBlocks[] = <<<FEATURE
<!-- wp:group {"style":{"spacing":{"padding":{"top":"var:preset|spacing|medium","bottom":"var:preset|spacing|medium","left":"var:preset|spacing|medium","right":"var:preset|spacing|medium"}}},"backgroundColor":"tertiary"} -->
<div class="wp-block-group has-tertiary-background-color has-background">

<!-- wp:paragraph {"fontSize":"large"} -->
<p class="has-large-font-size">{$icon}</p>
<!-- /wp:paragraph -->

<!-- wp:heading {"level":3} -->
<h3 class="wp-block-heading">{$title}</h3>
<!-- /wp:heading -->

<!-- wp:paragraph -->
<p>{$desc}</p>
<!-- /wp:paragraph -->

</div>
<!-- /wp:group -->
FEATURE;
        }

        $columns = count($featureBlocks) <= 3 ? count($featureBlocks) : 3;
        $columnsBlock = $this->wrapInColumns($featureBlocks, $columns);

        return <<<BLOCK
<!-- wp:group {"align":"full","style":{"spacing":{"padding":{"top":"var:preset|spacing|x-large","bottom":"var:preset|spacing|x-large"}}},"layout":{"type":"constrained"}} -->
<div class="wp-block-group alignfull">

<!-- wp:heading {"textAlign":"center"} -->
<h2 class="wp-block-heading has-text-align-center">{$sectionTitle}</h2>
<!-- /wp:heading -->

{$this->conditionalBlock($sectionIntro, fn ($t) => <<<INTRO
<!-- wp:paragraph {"align":"center"} -->
<p class="has-text-align-center">{$t}</p>
<!-- /wp:paragraph -->
INTRO)}

<!-- wp:spacer {"height":"var:preset|spacing|medium"} -->
<div style="height:var(--wp--preset--spacing--medium)" aria-hidden="true" class="wp-block-spacer"></div>
<!-- /wp:spacer -->

{$columnsBlock}

</div>
<!-- /wp:group -->
BLOCK;
    }

    private function generateTestimonialsBlock(array $content): string
    {
        $sectionTitle = $this->escape($content['section_title'] ?? 'What Our Clients Say');
        $testimonials = $content['testimonials'] ?? [];

        if (empty($testimonials)) {
            return '';
        }

        $testimonialBlocks = [];
        foreach (array_slice($testimonials, 0, 3) as $testimonial) {
            $quote = $this->escape($testimonial['quote'] ?? '');
            $author = $this->escape($testimonial['author'] ?? '');
            $title = $this->escape($testimonial['title'] ?? '');
            $avatar = $testimonial['avatar']['url'] ?? '';
            $rating = $testimonial['rating'] ?? 0;

            $ratingHtml = $rating > 0 ? str_repeat('⭐', min(5, $rating)) : '';

            $testimonialBlocks[] = <<<TESTIMONIAL
<!-- wp:group {"style":{"spacing":{"padding":{"top":"var:preset|spacing|medium","bottom":"var:preset|spacing|medium","left":"var:preset|spacing|medium","right":"var:preset|spacing|medium"}},"border":{"radius":"8px"}},"backgroundColor":"tertiary"} -->
<div class="wp-block-group has-tertiary-background-color has-background">

{$this->conditionalBlock($ratingHtml, fn ($r) => <<<RATING
<!-- wp:paragraph -->
<p>{$r}</p>
<!-- /wp:paragraph -->
RATING)}

<!-- wp:paragraph {"fontSize":"medium"} -->
<p class="has-medium-font-size">"{$quote}"</p>
<!-- /wp:paragraph -->

<!-- wp:group {"layout":{"type":"flex","flexWrap":"nowrap"}} -->
<div class="wp-block-group">

{$this->conditionalBlock($avatar, fn ($url) => <<<AVATAR
<!-- wp:image {"width":"48px","height":"48px","scale":"cover","sizeSlug":"thumbnail","style":{"border":{"radius":"100%"}}} -->
<figure class="wp-block-image size-thumbnail"><img src="{$url}" alt="" style="border-radius:100%;width:48px;height:48px;object-fit:cover"/></figure>
<!-- /wp:image -->
AVATAR)}

<!-- wp:group -->
<div class="wp-block-group">
<!-- wp:paragraph {"style":{"typography":{"fontWeight":"600"}}} -->
<p style="font-weight:600">{$author}</p>
<!-- /wp:paragraph -->
{$this->conditionalBlock($title, fn ($t) => <<<TITLE
<!-- wp:paragraph {"fontSize":"small","style":{"color":{"text":"var:preset|color|contrast-3"}}} -->
<p class="has-small-font-size">{$t}</p>
<!-- /wp:paragraph -->
TITLE)}
</div>
<!-- /wp:group -->

</div>
<!-- /wp:group -->

</div>
<!-- /wp:group -->
TESTIMONIAL;
        }

        $columnsBlock = $this->wrapInColumns($testimonialBlocks, min(3, count($testimonialBlocks)));

        return <<<BLOCK
<!-- wp:group {"align":"full","style":{"spacing":{"padding":{"top":"var:preset|spacing|x-large","bottom":"var:preset|spacing|x-large"}}},"backgroundColor":"secondary","layout":{"type":"constrained"}} -->
<div class="wp-block-group alignfull has-secondary-background-color has-background">

<!-- wp:heading {"textAlign":"center","style":{"color":{"text":"#ffffff"}}} -->
<h2 class="wp-block-heading has-text-align-center" style="color:#ffffff">{$sectionTitle}</h2>
<!-- /wp:heading -->

<!-- wp:spacer {"height":"var:preset|spacing|medium"} -->
<div style="height:var(--wp--preset--spacing--medium)" aria-hidden="true" class="wp-block-spacer"></div>
<!-- /wp:spacer -->

{$columnsBlock}

</div>
<!-- /wp:group -->
BLOCK;
    }

    private function generatePricingBlock(array $content): string
    {
        $sectionTitle = $this->escape($content['section_title'] ?? 'Pricing');
        $plans = $content['plans'] ?? [];

        if (empty($plans)) {
            return '';
        }

        $planBlocks = [];
        foreach (array_slice($plans, 0, 3) as $plan) {
            $name = $this->escape($plan['name'] ?? 'Plan');
            $currency = $plan['currency'] ?? '$';
            $price = $plan['price'] ?? '0';
            $period = $plan['period'] ?? 'mo';
            $features = $plan['features'] ?? [];
            $featured = $plan['featured'] ?? false;
            $cta = $plan['cta'] ?? null;

            $featuresHtml = '';
            if (! empty($features)) {
                $featuresHtml = "<!-- wp:list -->\n<ul class=\"wp-block-list\">\n";
                foreach (array_slice($features, 0, 8) as $feature) {
                    $featuresHtml .= '<li>'.$this->escape($feature)."</li>\n";
                }
                $featuresHtml .= "</ul>\n<!-- /wp:list -->";
            }

            $bgColor = $featured ? 'primary' : 'tertiary';
            $textColor = $featured ? '#ffffff' : '';
            $textStyle = $textColor ? " style=\"color:{$textColor}\"" : '';

            $buttonHtml = '';
            if ($cta) {
                $buttonHtml = $this->generateButtonBlock($cta, $featured ? 'outline' : 'fill');
            }

            $planBlocks[] = <<<PLAN
<!-- wp:group {"style":{"spacing":{"padding":{"top":"var:preset|spacing|large","bottom":"var:preset|spacing|large","left":"var:preset|spacing|medium","right":"var:preset|spacing|medium"}},"border":{"radius":"8px"}},"backgroundColor":"{$bgColor}"} -->
<div class="wp-block-group has-{$bgColor}-background-color has-background">

<!-- wp:heading {"textAlign":"center","level":3}{$textStyle} -->
<h3 class="wp-block-heading has-text-align-center"{$textStyle}>{$name}</h3>
<!-- /wp:heading -->

<!-- wp:paragraph {"align":"center","fontSize":"x-large"}{$textStyle} -->
<p class="has-text-align-center has-x-large-font-size"{$textStyle}><strong>{$currency}{$price}</strong><span style="font-size:0.5em">/{$period}</span></p>
<!-- /wp:paragraph -->

{$featuresHtml}

{$buttonHtml}

</div>
<!-- /wp:group -->
PLAN;
        }

        $columnsBlock = $this->wrapInColumns($planBlocks, count($planBlocks));

        return <<<BLOCK
<!-- wp:group {"align":"full","style":{"spacing":{"padding":{"top":"var:preset|spacing|x-large","bottom":"var:preset|spacing|x-large"}}},"layout":{"type":"constrained"}} -->
<div class="wp-block-group alignfull">

<!-- wp:heading {"textAlign":"center"} -->
<h2 class="wp-block-heading has-text-align-center">{$sectionTitle}</h2>
<!-- /wp:heading -->

<!-- wp:spacer {"height":"var:preset|spacing|medium"} -->
<div style="height:var(--wp--preset--spacing--medium)" aria-hidden="true" class="wp-block-spacer"></div>
<!-- /wp:spacer -->

{$columnsBlock}

</div>
<!-- /wp:group -->
BLOCK;
    }

    private function generateTeamBlock(array $content): string
    {
        $sectionTitle = $this->escape($content['section_title'] ?? 'Our Team');
        $members = $content['members'] ?? [];

        if (empty($members)) {
            return '';
        }

        $memberBlocks = [];
        foreach (array_slice($members, 0, 8) as $member) {
            $name = $this->escape($member['name'] ?? '');
            $title = $this->escape($member['title'] ?? '');
            $bio = $this->escape($member['bio'] ?? '');
            $photo = $member['photo']['url'] ?? '';

            $photoBlock = $photo ? <<<PHOTO
<!-- wp:image {"sizeSlug":"medium","style":{"border":{"radius":"8px"}}} -->
<figure class="wp-block-image size-medium"><img src="{$photo}" alt="{$name}" style="border-radius:8px"/></figure>
<!-- /wp:image -->
PHOTO : '';

            $memberBlocks[] = <<<MEMBER
<!-- wp:group {"style":{"spacing":{"blockGap":"var:preset|spacing|small"}}} -->
<div class="wp-block-group">

{$photoBlock}

<!-- wp:heading {"level":4} -->
<h4 class="wp-block-heading">{$name}</h4>
<!-- /wp:heading -->

{$this->conditionalBlock($title, fn ($t) => <<<TITLE
<!-- wp:paragraph {"fontSize":"small","style":{"color":{"text":"var:preset|color|primary"}}} -->
<p class="has-small-font-size">{$t}</p>
<!-- /wp:paragraph -->
TITLE)}

{$this->conditionalBlock($bio, fn ($b) => <<<BIO
<!-- wp:paragraph {"fontSize":"small"} -->
<p class="has-small-font-size">{$b}</p>
<!-- /wp:paragraph -->
BIO)}

</div>
<!-- /wp:group -->
MEMBER;
        }

        $columns = min(4, count($memberBlocks));
        $columnsBlock = $this->wrapInColumns($memberBlocks, $columns);

        return <<<BLOCK
<!-- wp:group {"align":"full","style":{"spacing":{"padding":{"top":"var:preset|spacing|x-large","bottom":"var:preset|spacing|x-large"}}},"layout":{"type":"constrained"}} -->
<div class="wp-block-group alignfull">

<!-- wp:heading {"textAlign":"center"} -->
<h2 class="wp-block-heading has-text-align-center">{$sectionTitle}</h2>
<!-- /wp:heading -->

<!-- wp:spacer {"height":"var:preset|spacing|medium"} -->
<div style="height:var(--wp--preset--spacing--medium)" aria-hidden="true" class="wp-block-spacer"></div>
<!-- /wp:spacer -->

{$columnsBlock}

</div>
<!-- /wp:group -->
BLOCK;
    }

    private function generateFaqBlock(array $content): string
    {
        $sectionTitle = $this->escape($content['section_title'] ?? 'Frequently Asked Questions');
        $faqs = $content['faqs'] ?? [];

        if (empty($faqs)) {
            return '';
        }

        $faqBlocks = [];
        foreach (array_slice($faqs, 0, 15) as $faq) {
            $question = $this->escape($faq['question'] ?? '');
            $answer = $this->escape($faq['answer'] ?? '');

            $faqBlocks[] = <<<FAQ
<!-- wp:details -->
<details class="wp-block-details">
<summary>{$question}</summary>
<!-- wp:paragraph -->
<p>{$answer}</p>
<!-- /wp:paragraph -->
</details>
<!-- /wp:details -->
FAQ;
        }

        $faqsHtml = implode("\n\n", $faqBlocks);

        return <<<BLOCK
<!-- wp:group {"align":"full","style":{"spacing":{"padding":{"top":"var:preset|spacing|x-large","bottom":"var:preset|spacing|x-large"}}},"layout":{"type":"constrained"}} -->
<div class="wp-block-group alignfull">

<!-- wp:heading {"textAlign":"center"} -->
<h2 class="wp-block-heading has-text-align-center">{$sectionTitle}</h2>
<!-- /wp:heading -->

<!-- wp:spacer {"height":"var:preset|spacing|medium"} -->
<div style="height:var(--wp--preset--spacing--medium)" aria-hidden="true" class="wp-block-spacer"></div>
<!-- /wp:spacer -->

<!-- wp:group {"style":{"spacing":{"blockGap":"var:preset|spacing|small"}},"layout":{"type":"constrained","contentSize":"720px"}} -->
<div class="wp-block-group">
{$faqsHtml}
</div>
<!-- /wp:group -->

</div>
<!-- /wp:group -->
BLOCK;
    }

    private function generateCtaBlock(array $content): string
    {
        $headline = $this->escape($content['headline'] ?? 'Ready to get started?');
        $body = $this->escape($content['body'] ?? '');
        $buttons = $content['buttons'] ?? [];

        $buttonsHtml = '';
        if (! empty($buttons)) {
            $buttonBlocks = [];
            foreach (array_slice($buttons, 0, 2) as $i => $button) {
                $style = $i === 0 ? 'fill' : 'outline';
                $buttonBlocks[] = $this->generateButtonBlock($button, $style);
            }
            $buttonsHtml = $this->wrapInButtonsGroup($buttonBlocks);
        }

        return <<<BLOCK
<!-- wp:group {"align":"full","style":{"spacing":{"padding":{"top":"var:preset|spacing|x-large","bottom":"var:preset|spacing|x-large"}}},"backgroundColor":"primary","layout":{"type":"constrained"}} -->
<div class="wp-block-group alignfull has-primary-background-color has-background">

<!-- wp:heading {"textAlign":"center","style":{"color":{"text":"#ffffff"}}} -->
<h2 class="wp-block-heading has-text-align-center" style="color:#ffffff">{$headline}</h2>
<!-- /wp:heading -->

{$this->conditionalBlock($body, fn ($b) => <<<BODY
<!-- wp:paragraph {"align":"center","style":{"color":{"text":"#ffffff"}}} -->
<p class="has-text-align-center" style="color:#ffffff">{$b}</p>
<!-- /wp:paragraph -->
BODY)}

{$buttonsHtml}

</div>
<!-- /wp:group -->
BLOCK;
    }

    private function generateContactBlock(array $content): string
    {
        $sectionTitle = $this->escape($content['section_title'] ?? 'Contact Us');
        $contactInfo = $content['contact_info'] ?? [];
        $hasForm = ! empty($content['form']);

        $infoBlocks = [];
        if (! empty($contactInfo['email'])) {
            $email = $this->escape($contactInfo['email']);
            $infoBlocks[] = <<<INFO
<!-- wp:paragraph -->
<p><strong>Email:</strong> <a href="mailto:{$email}">{$email}</a></p>
<!-- /wp:paragraph -->
INFO;
        }
        if (! empty($contactInfo['phone'])) {
            $phone = $this->escape($contactInfo['phone']);
            $infoBlocks[] = <<<INFO
<!-- wp:paragraph -->
<p><strong>Phone:</strong> <a href="tel:{$phone}">{$phone}</a></p>
<!-- /wp:paragraph -->
INFO;
        }
        if (! empty($contactInfo['address'])) {
            $address = $this->escape($contactInfo['address']);
            $infoBlocks[] = <<<INFO
<!-- wp:paragraph -->
<p><strong>Address:</strong> {$address}</p>
<!-- /wp:paragraph -->
INFO;
        }

        $infoHtml = implode("\n", $infoBlocks);

        $formHtml = '';
        if ($hasForm) {
            $formHtml = <<<'FORM'
<!-- wp:group {"style":{"spacing":{"padding":{"top":"var:preset|spacing|medium","bottom":"var:preset|spacing|medium","left":"var:preset|spacing|medium","right":"var:preset|spacing|medium"}}},"backgroundColor":"tertiary"} -->
<div class="wp-block-group has-tertiary-background-color has-background">

<!-- wp:heading {"level":3} -->
<h3 class="wp-block-heading">Send us a message</h3>
<!-- /wp:heading -->

<!-- wp:paragraph {"fontSize":"small"} -->
<p class="has-small-font-size">Fill out the form below and we'll get back to you soon.</p>
<!-- /wp:paragraph -->

<!-- wp:buttons -->
<div class="wp-block-buttons">
<!-- wp:button -->
<div class="wp-block-button"><a class="wp-block-button__link wp-element-button">Contact Form Placeholder</a></div>
<!-- /wp:button -->
</div>
<!-- /wp:buttons -->

</div>
<!-- /wp:group -->
FORM;
        }

        $leftColumn = <<<LEFT
<!-- wp:group -->
<div class="wp-block-group">
{$infoHtml}
</div>
<!-- /wp:group -->
LEFT;

        return <<<BLOCK
<!-- wp:group {"align":"full","style":{"spacing":{"padding":{"top":"var:preset|spacing|x-large","bottom":"var:preset|spacing|x-large"}}},"layout":{"type":"constrained"}} -->
<div class="wp-block-group alignfull">

<!-- wp:heading {"textAlign":"center"} -->
<h2 class="wp-block-heading has-text-align-center">{$sectionTitle}</h2>
<!-- /wp:heading -->

<!-- wp:spacer {"height":"var:preset|spacing|medium"} -->
<div style="height:var(--wp--preset--spacing--medium)" aria-hidden="true" class="wp-block-spacer"></div>
<!-- /wp:spacer -->

<!-- wp:columns -->
<div class="wp-block-columns">

<!-- wp:column -->
<div class="wp-block-column">
{$leftColumn}
</div>
<!-- /wp:column -->

<!-- wp:column -->
<div class="wp-block-column">
{$formHtml}
</div>
<!-- /wp:column -->

</div>
<!-- /wp:columns -->

</div>
<!-- /wp:group -->
BLOCK;
    }

    private function generateGalleryBlock(array $content): string
    {
        $sectionTitle = $this->escape($content['section_title'] ?? 'Gallery');
        $images = $content['images'] ?? [];

        if (empty($images)) {
            return '';
        }

        $imageBlocks = [];
        foreach (array_slice($images, 0, 12) as $image) {
            $url = $image['url'] ?? '';
            $alt = $this->escape($image['alt'] ?? '');
            $imageBlocks[] = <<<IMAGE
<!-- wp:image {"sizeSlug":"large"} -->
<figure class="wp-block-image size-large"><img src="{$url}" alt="{$alt}"/></figure>
<!-- /wp:image -->
IMAGE;
        }

        $galleryHtml = implode("\n", $imageBlocks);

        return <<<BLOCK
<!-- wp:group {"align":"full","style":{"spacing":{"padding":{"top":"var:preset|spacing|x-large","bottom":"var:preset|spacing|x-large"}}},"layout":{"type":"constrained"}} -->
<div class="wp-block-group alignfull">

<!-- wp:heading {"textAlign":"center"} -->
<h2 class="wp-block-heading has-text-align-center">{$sectionTitle}</h2>
<!-- /wp:heading -->

<!-- wp:spacer {"height":"var:preset|spacing|medium"} -->
<div style="height:var(--wp--preset--spacing--medium)" aria-hidden="true" class="wp-block-spacer"></div>
<!-- /wp:spacer -->

<!-- wp:gallery {"columns":3,"linkTo":"none"} -->
<figure class="wp-block-gallery has-nested-images columns-3 is-cropped">
{$galleryHtml}
</figure>
<!-- /wp:gallery -->

</div>
<!-- /wp:group -->
BLOCK;
    }

    private function generateBlogBlock(array $content): string
    {
        $sectionTitle = $this->escape($content['section_title'] ?? 'Latest Posts');
        $posts = $content['posts'] ?? [];

        if (empty($posts)) {
            return <<<BLOCK
<!-- wp:group {"align":"full","style":{"spacing":{"padding":{"top":"var:preset|spacing|x-large","bottom":"var:preset|spacing|x-large"}}},"layout":{"type":"constrained"}} -->
<div class="wp-block-group alignfull">

<!-- wp:heading {"textAlign":"center"} -->
<h2 class="wp-block-heading has-text-align-center">{$sectionTitle}</h2>
<!-- /wp:heading -->

<!-- wp:query {"queryId":1,"query":{"perPage":3,"pages":0,"offset":0,"postType":"post","order":"desc","orderBy":"date","author":"","search":"","exclude":[],"sticky":"","inherit":false}} -->
<div class="wp-block-query">

<!-- wp:post-template {"layout":{"type":"grid","columnCount":3}} -->

<!-- wp:group {"style":{"spacing":{"blockGap":"var:preset|spacing|small"}}} -->
<div class="wp-block-group">

<!-- wp:post-featured-image {"isLink":true,"aspectRatio":"16/9"} /-->

<!-- wp:post-title {"isLink":true,"level":3} /-->

<!-- wp:post-excerpt {"moreText":"Read More"} /-->

</div>
<!-- /wp:group -->

<!-- /wp:post-template -->

</div>
<!-- /wp:query -->

</div>
<!-- /wp:group -->
BLOCK;
        }

        $postBlocks = [];
        foreach (array_slice($posts, 0, 3) as $post) {
            $title = $this->escape($post['title'] ?? '');
            $excerpt = $this->escape($post['excerpt'] ?? '');
            $image = $post['image']['url'] ?? '';
            $url = $post['url'] ?? '#';

            $imageBlock = $image ? <<<IMG
<!-- wp:image {"sizeSlug":"large","style":{"border":{"radius":"8px"}}} -->
<figure class="wp-block-image size-large"><a href="{$url}"><img src="{$image}" alt="" style="border-radius:8px"/></a></figure>
<!-- /wp:image -->
IMG : '';

            $postBlocks[] = <<<POST
<!-- wp:group {"style":{"spacing":{"blockGap":"var:preset|spacing|small"}}} -->
<div class="wp-block-group">

{$imageBlock}

<!-- wp:heading {"level":3} -->
<h3 class="wp-block-heading"><a href="{$url}">{$title}</a></h3>
<!-- /wp:heading -->

<!-- wp:paragraph {"fontSize":"small"} -->
<p class="has-small-font-size">{$excerpt}</p>
<!-- /wp:paragraph -->

</div>
<!-- /wp:group -->
POST;
        }

        $columnsBlock = $this->wrapInColumns($postBlocks, count($postBlocks));

        return <<<BLOCK
<!-- wp:group {"align":"full","style":{"spacing":{"padding":{"top":"var:preset|spacing|x-large","bottom":"var:preset|spacing|x-large"}}},"layout":{"type":"constrained"}} -->
<div class="wp-block-group alignfull">

<!-- wp:heading {"textAlign":"center"} -->
<h2 class="wp-block-heading has-text-align-center">{$sectionTitle}</h2>
<!-- /wp:heading -->

<!-- wp:spacer {"height":"var:preset|spacing|medium"} -->
<div style="height:var(--wp--preset--spacing--medium)" aria-hidden="true" class="wp-block-spacer"></div>
<!-- /wp:spacer -->

{$columnsBlock}

</div>
<!-- /wp:group -->
BLOCK;
    }

    private function generateButtonBlock(array $button, string $style = 'fill'): string
    {
        $text = $this->escape($button['text'] ?? 'Learn More');
        $url = $button['url'] ?? '#';

        $className = $style === 'outline' ? 'is-style-outline' : '';
        $attrs = ['className' => $className];

        return <<<BUTTON
<!-- wp:button {$this->jsonAttr($attrs)} -->
<div class="wp-block-button {$className}"><a class="wp-block-button__link wp-element-button" href="{$url}">{$text}</a></div>
<!-- /wp:button -->
BUTTON;
    }

    private function wrapInButtonsGroup(array $buttonBlocks): string
    {
        if (empty($buttonBlocks)) {
            return '';
        }

        $buttonsHtml = implode("\n", $buttonBlocks);

        return <<<BUTTONS
<!-- wp:buttons {"layout":{"type":"flex","justifyContent":"center"}} -->
<div class="wp-block-buttons">
{$buttonsHtml}
</div>
<!-- /wp:buttons -->
BUTTONS;
    }

    private function wrapInColumns(array $blocks): string
    {
        if (empty($blocks)) {
            return '';
        }

        $columnBlocks = [];
        foreach ($blocks as $block) {
            $columnBlocks[] = <<<COL
<!-- wp:column -->
<div class="wp-block-column">
{$block}
</div>
<!-- /wp:column -->
COL;
        }

        $columnsHtml = implode("\n\n", $columnBlocks);

        return <<<COLUMNS
<!-- wp:columns -->
<div class="wp-block-columns">
{$columnsHtml}
</div>
<!-- /wp:columns -->
COLUMNS;
    }

    private function conditionalBlock(string $value, callable $generator): string
    {
        return ! empty(trim($value)) ? $generator($value) : '';
    }

    private function escape(string $text): string
    {
        return htmlspecialchars($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }

    private function jsonAttr(array $attrs): string
    {
        $filtered = array_filter($attrs, fn ($v) => ! empty($v));
        if (empty($filtered)) {
            return '';
        }

        return json_encode($filtered, JSON_UNESCAPED_SLASHES);
    }
}
