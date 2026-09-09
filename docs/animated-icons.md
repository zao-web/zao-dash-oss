# Animated Icons

Zao Dash uses a custom set of animated Vue icon components inspired by [itshover.com](https://www.itshover.com/icons) and [lucide-animated](https://github.com/pqoqubbw/icons).

## Installation

The icons rely on CSS transitions and transforms for animations. No additional dependencies are required beyond Vue 3.

## Usage

Import icons from `@/Components/Icons`:

```vue
<script setup>
import { CommandIcon, SparklesIcon, ChartIcon } from '@/Components/Icons';
</script>

<template>
    <CommandIcon :size="20" />
    <SparklesIcon :size="16" />
    <ChartIcon :size="24" />
</template>
```

## Available Icons

### Navigation & Layout
- `BookIcon` - Open book
- `BriefcaseIcon` - Work briefcase
- `ChartIcon` - Bar chart
- `ChevronIcon` - Directional chevron (supports `direction` and `expanded` props)
- `CogIcon` - Settings gear
- `CommandIcon` - Grid/dashboard command icon
- `FolderIcon` - File folder
- `MenuIcon` - Hamburger menu (supports `isOpen` prop)
- `PlugIcon` - Connection/link chain
- `SearchIcon` - Magnifying glass

### Actions
- `CheckIcon` - Checkmark in circle
- `CloseIcon` - X close icon
- `CopyIcon` - Duplicate/copy
- `DownloadIcon` - Download arrow
- `EditIcon` - Pencil edit
- `ExternalLinkIcon` - External link arrow
- `PlayIcon` - Play button
- `PlusIcon` - Add/plus
- `RefreshIcon` - Refresh/reload
- `TrashIcon` - Delete trash

### People & Users
- `LogoutIcon` - Sign out
- `TeamIcon` - Group of people
- `UserIcon` - Single person
- `UsersIcon` - Multiple people

### Media & Content
- `VideoIcon` - Video camera
- `DocumentIcon` - Document/file

### Business & Finance
- `CurrencyIcon` - Dollar sign
- `FunnelIcon` - Sales funnel
- `WalletIcon` - Wallet

### AI & System
- `CpuIcon` - Processor chip
- `SparklesIcon` - AI sparkles

### Security & Status
- `LockIcon` - Padlock
- `ShieldIcon` - Shield with checkmark
- `WarningIcon` - Warning triangle

### UI & Feedback
- `ArrowRightIcon` - Right arrow
- `BellIcon` - Notification bell
- `MoonIcon` - Dark mode moon
- `SunIcon` - Light mode sun

## Props

All icons accept:

| Prop | Type | Default | Description |
|------|------|---------|-------------|
| `size` | `number` | `20` | Icon size in pixels |

Some icons have additional props:

### ChevronIcon
| Prop | Type | Default | Description |
|------|------|---------|-------------|
| `direction` | `'up' \| 'down' \| 'left' \| 'right'` | `'right'` | Arrow direction |
| `expanded` | `boolean` | `false` | Whether in expanded state (rotates 90 degrees) |

### MenuIcon
| Prop | Type | Default | Description |
|------|------|---------|-------------|
| `isOpen` | `boolean` | `false` | Shows X when true, hamburger when false |

## Animation Behavior

Each icon animates on hover with smooth CSS transitions:

- **Scale** - Icons slightly grow on hover
- **Rotate** - Gear, sun, and settings icons rotate
- **Translate** - Arrows and directional icons shift
- **Shake** - Bell, warning, and trash icons shake
- **Glow** - Sparkles and special icons pulse

Animations use `cubic-bezier(0.4, 0, 0.2, 1)` timing for smooth, natural movement.

## Adding New Icons

1. Create a new `.vue` file in `/resources/js/Components/Icons/`
2. Use the standard template:

```vue
<script setup lang="ts">
import { ref } from 'vue';

defineProps<{
    size?: number;
}>();

const isHovered = ref(false);
</script>

<template>
    <div
        class="icon-wrapper"
        @mouseenter="isHovered = true"
        @mouseleave="isHovered = false"
    >
        <svg
            :width="size ?? 20"
            :height="size ?? 20"
            viewBox="0 0 24 24"
            fill="none"
            stroke="currentColor"
            stroke-width="1.5"
            stroke-linecap="round"
            stroke-linejoin="round"
        >
            <!-- Add paths with :style binding for animation -->
            <path
                d="M..."
                :style="{
                    transform: isHovered ? 'scale(1.1)' : 'scale(1)',
                    transition: 'transform 0.25s cubic-bezier(0.4, 0, 0.2, 1)'
                }"
            />
        </svg>
    </div>
</template>

<style scoped>
.icon-wrapper {
    display: inline-flex;
    align-items: center;
    justify-content: center;
}
</style>
```

3. Export from `/resources/js/Components/Icons/index.ts`

## Credits

Design inspired by:
- [Its Hover](https://www.itshover.com/icons) - Animated icons that move with intent
- [Lucide Animated](https://github.com/pqoqubbw/icons) - Beautifully crafted animated icons
- [Heroicons](https://heroicons.com/) - SVG icon paths
