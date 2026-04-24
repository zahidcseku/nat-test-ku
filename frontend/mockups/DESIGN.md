# Design System Document

## 1. Overview & Creative North Star: "The Modern Archive"
This design system is built to evoke the structured, authoritative atmosphere of a digital research repository or a modern civic institution. It moves toward a highly legible, unified aesthetic to create a **Streamlined Professional** experience.

The Creative North Star is **The Modern Archive**. Every interaction should feel efficient, stable, and clear. We achieve this through:
*   **Structured Alignment:** Utilizing a consistent grid system that prioritizes logical flow over editorial flair.
*   **Tonal Depth:** Using soft shifts in value rather than harsh lines to define the UI hierarchy.
*   **Typographic Unity:** Moving away from high-contrast font pairings to a singular, robust sans-serif system that signals technical precision and objectivity.

---

## 2. Colors & The Hierarchy of Light
The palette is rooted in the depth of `primary` (#002147) and the clarity of `neutral` (#F8FAFC).

### The "No-Line" Rule
To maintain a premium, clean feel, **1px solid borders are minimized** for sectioning. Structural boundaries are created through:
*   **Background Shifts:** Place a `surface_container_low` section against a `surface` background to define a zone.
*   **Negative Space:** Use the Spacing Scale (Level 2) to create clear, balanced gutters that naturally separate content.

### Surface Hierarchy & Nesting
Treat the interface as a series of clean, professional layers.
*   **Base:** `surface` (#F8FAFC) for the main canvas.
*   **Secondary Zones:** `surface_container_low` for sidebar navigation or footer areas.
*   **Interactive Cards:** `surface_container_lowest` (#ffffff) to provide contrast against the off-white base.

### Signature Textures (Gradients)
To prevent the deep navy from feeling "flat," use a subtle linear gradient for hero sections and primary CTAs:
*   **From:** `primary` (#002147)
*   **To:** A slightly lighter variant at a 135-degree angle.

---

## 3. Typography: The Engine of Clarity
We utilize a unified typographic approach to ensure maximum legibility and a contemporary feel.

*   **The Voice & Engine:** `publicSans` is our primary typeface for both headings and body. It provides a neutral, highly readable foundation that works across all data densities.
*   **Hierarchy:** Use `display-lg` (3.5rem) for main titles and `headline-md` (1.75rem) for section titles. The use of bold weights in Public Sans provides authority without the traditionalism of a serif.
*   **Rhythm:** Maintain a balanced line-height (1.5x) for body text to ensure candidates can navigate dense exam instructions efficiently.

---

## 4. Elevation & Depth: Tonal Layering
Depth is achieved through **Tonal Layering** rather than heavy shadows.

*   **The Layering Principle:** Depth is achieved by stacking. A `surface_container_highest` element represents the "bottom" layer, while `surface_container_lowest` (#ffffff) represents the "top" layer closest to the user.
*   **Subtle Shapes:** With a `roundedness` of 1, corners are slightly softened to feel approachable while maintaining a disciplined, professional appearance.
*   **Ambient Shadows:** If a card must float, use an extra-diffused, subtle shadow to ensure it doesn't distract from the information.

---

## 5. Components

### Buttons: Functional Weight
*   **Primary:** Solid `primary` (#002147) with `on_primary` (#ffffff) text. Use `subtle` (roundedness 1) corners. These should feel intentional and decisive.
*   **Secondary:** `surface_container_high` background with `primary` text. No border.
*   **Tertiary:** Ghost style. No background, `primary` text.

### Input Fields: Minimalist Clarity
*   **Style:** Subtle background fill or a bottom border using `outline_variant`.
*   **Focus State:** The border transitions to `primary` (#002147) with a 2px thickness to guide the user's attention.

### Cards & Lists: Balanced Structure
*   **Cards:** Use `surface_container_low` for the card body on a `surface` background. Corners follow the `roundedness: 1` constraint.
*   **Lists:** Use balanced vertical white space (Spacing 2) and a subtle background shift on hover to indicate interactivity.

### Specialized NAT Components
*   **Timer Display:** Use `headline-sm` (publicSans) for the numerals, providing a clean, digital-first look at the passage of time.
*   **Progress Indicator:** Use a thin track of `surface_container_highest` with a `primary` fill. Caps follow the system's `roundedness: 1` for a subtle curve.

---

## 6. Do’s and Don’ts

### Do:
*   **Prioritize Legibility:** With the switch to a unified sans-serif system, focus on using weight and scale to create hierarchy.
*   **Maintain Balanced Spacing:** Use the level 2 spacing scale to keep the UI feeling organized and professional without being overly sparse.
*   **Use Subtle Color Shifts:** Define different functional zones by shifting background values rather than adding borders.

### Don't:
*   **Don’t mix Font Families:** Stick to Public Sans for all UI and content to maintain the "Modern Archive" look.
*   **Don’t use High-Contrast Borders:** Avoid hard lines that create visual "noise" for test-takers.
*   **Don’t use Excessive Roundedness:** Stick to the `roundedness: 1` setting to ensure the UI feels architectural rather than playful.