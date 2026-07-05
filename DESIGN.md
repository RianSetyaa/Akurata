---
name: Akurata
colors:
  surface: '#f7f9fb'
  surface-dim: '#d8dadc'
  surface-bright: '#f7f9fb'
  surface-container-lowest: '#ffffff'
  surface-container-low: '#f2f4f6'
  surface-container: '#eceef0'
  surface-container-high: '#e6e8ea'
  surface-container-highest: '#e0e3e5'
  on-surface: '#191c1e'
  on-surface-variant: '#45464d'
  inverse-surface: '#2d3133'
  inverse-on-surface: '#eff1f3'
  outline: '#76777d'
  outline-variant: '#c6c6cd'
  surface-tint: '#565e74'
  primary: '#000000'
  on-primary: '#ffffff'
  primary-container: '#131b2e'
  on-primary-container: '#7c839b'
  inverse-primary: '#bec6e0'
  secondary: '#00687a'
  on-secondary: '#ffffff'
  secondary-container: '#57dffe'
  on-secondary-container: '#006172'
  tertiary: '#000000'
  on-tertiary: '#ffffff'
  tertiary-container: '#0b1c30'
  on-tertiary-container: '#75859d'
  error: '#ba1a1a'
  on-error: '#ffffff'
  error-container: '#ffdad6'
  on-error-container: '#93000a'
  primary-fixed: '#dae2fd'
  primary-fixed-dim: '#bec6e0'
  on-primary-fixed: '#131b2e'
  on-primary-fixed-variant: '#3f465c'
  secondary-fixed: '#acedff'
  secondary-fixed-dim: '#4cd7f6'
  on-secondary-fixed: '#001f26'
  on-secondary-fixed-variant: '#004e5c'
  tertiary-fixed: '#d3e4fe'
  tertiary-fixed-dim: '#b7c8e1'
  on-tertiary-fixed: '#0b1c30'
  on-tertiary-fixed-variant: '#38485d'
  background: '#f7f9fb'
  on-background: '#191c1e'
  surface-variant: '#e0e3e5'
typography:
  display:
    fontFamily: Inter
    fontSize: 48px
    fontWeight: '700'
    lineHeight: 56px
    letterSpacing: -0.02em
  headline-lg:
    fontFamily: Inter
    fontSize: 32px
    fontWeight: '600'
    lineHeight: 40px
    letterSpacing: -0.01em
  headline-lg-mobile:
    fontFamily: Inter
    fontSize: 24px
    fontWeight: '600'
    lineHeight: 32px
  headline-md:
    fontFamily: Inter
    fontSize: 24px
    fontWeight: '600'
    lineHeight: 32px
  title-lg:
    fontFamily: Inter
    fontSize: 20px
    fontWeight: '600'
    lineHeight: 28px
  body-lg:
    fontFamily: Inter
    fontSize: 16px
    fontWeight: '400'
    lineHeight: 24px
  body-md:
    fontFamily: Inter
    fontSize: 14px
    fontWeight: '400'
    lineHeight: 20px
  label-md:
    fontFamily: Inter
    fontSize: 12px
    fontWeight: '500'
    lineHeight: 16px
    letterSpacing: 0.01em
  data-mono:
    fontFamily: JetBrains Mono
    fontSize: 14px
    fontWeight: '500'
    lineHeight: 20px
rounded:
  sm: 0.25rem
  DEFAULT: 0.5rem
  md: 0.75rem
  lg: 1rem
  xl: 1.5rem
  full: 9999px
spacing:
  unit: 4px
  container-max-width: 1440px
  gutter: 24px
  margin-mobile: 16px
  margin-desktop: 32px
---

## Brand & Style

The design system is engineered for a professional accounting platform where precision and reliability are paramount. The brand personality is authoritative yet accessible, positioning itself as a high-performance tool for modern financial management. 

The aesthetic follows a **Modern Corporate Minimalism** approach. It prioritizes data density without sacrificing legibility. The UI utilizes high-contrast elements to distinguish between navigational structures and actionable data, evoking a sense of calm efficiency. Every interface element is designed to feel intentional and mathematically grounded, mirroring the accuracy of the accounting profession.

## Colors

The palette is anchored by **Deep Navy (#0F172A)**, used for primary branding and navigation to establish a foundation of institutional trust. **Vibrant Cyan (#06B6D4)** serves as the high-energy accent, reserved for primary actions, progress indicators, and active states to provide a modern, tech-forward contrast.

The background system uses a tiered grayscale:
- **Surface:** White (#FFFFFF) for primary content cards.
- **Background:** Soft Gray (#F8FAFC) for the application canvas.
- **Muted Text:** Slate (#64748B) for secondary information and labels.

Success, warning, and error states should utilize standard semantic tones (Green, Amber, Red) but adjusted to match the saturation levels of the primary Cyan to maintain visual harmony.

## Typography

The design system utilizes **Inter** as the primary typeface due to its exceptional legibility in data-heavy environments and its neutral, professional character. For numerical data and financial ledgers, **JetBrains Mono** is introduced as a secondary utility font to ensure tabular alignment and clarity when comparing figures.

- **Headlines:** Set with tight tracking and semi-bold weights to command attention.
- **Body:** Standardized at 14px for optimal information density in complex dashboards.
- **Data:** All monetary values should use the `data-mono` style to ensure digit alignment across columns.

## Layout & Spacing

The layout is based on a **12-column fluid grid** for desktop and a **4-column grid** for mobile. A strict 4px base unit governs all spatial relationships.

- **Dashboards:** Use a "Sidebar + Header + Content" structure. The sidebar is fixed at 280px, while the main content area expands.
- **Data Grids:** Use 12px vertical padding for list items to maintain a "high-density" feel while ensuring touch targets are accessible.
- **Margins:** 32px external margins on desktop allow the content to breathe; these collapse to 16px on mobile devices.

## Elevation & Depth

This design system uses a **Tonal Layering** approach combined with **Subtle Ambient Shadows**. Depth is used sparingly to denote functional priority:

1.  **Level 0 (Base):** The #F8FAFC background.
2.  **Level 1 (Card):** White surfaces with a 1px border (#E2E8F0) and no shadow.
3.  **Level 2 (Interactive):** Elements like dropdowns and modals use a soft, diffused shadow: `0px 4px 12px rgba(15, 23, 42, 0.08)`.
4.  **Level 3 (Overlay):** High-priority alerts use a more pronounced shadow with a Deep Navy tint to create separation from the UI.

Avoid heavy blurs or colorful glows; keep shadows neutral and gray-scaled to maintain the professional tone.

## Shapes

The design system employs a **Rounded** corner strategy to soften the industrial feel of financial software. 

- **Standard Buttons & Inputs:** 8px (0.5rem) radius for a modern, approachable feel.
- **Cards & Containers:** 12px (0.75rem) or 16px (1rem) for larger surface areas.
- **Status Tags/Chips:** Fully rounded (pill-shaped) to distinguish them from interactive buttons.

## Components

### Buttons
- **Primary:** Deep Navy background with White text. Bold and authoritative.
- **Secondary:** Vibrant Cyan background with White text. Used for "Add" or "Create" actions.
- **Ghost:** Transparent background with a 1px Slate border for secondary navigation.

### Input Fields
- **Default State:** 1px Slate-200 border, 8px radius.
- **Focus State:** 2px Vibrant Cyan border with a soft cyan outer glow (4px spread).
- **Labels:** Always positioned above the field in `label-md` Slate text.

### Data Tables
- **Header:** Light gray background (#F1F5F9) with uppercase `label-md` text.
- **Rows:** Alternating zebra stripes are discouraged; use thin 1px horizontal dividers instead to maintain a clean aesthetic.
- **Hover:** Apply a very subtle blue tint (#F0FDFA) to the active row.

### Financial Cards
- Top-level metrics (Total Revenue, Expenses) should be housed in white cards with 16px padding and a secondary-colored accent bar (2px wide) on the left edge.