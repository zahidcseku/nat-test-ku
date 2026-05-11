# TBD (To Be Determined) Features

This file tracks features and functionality that are planned for future implementation but are not currently active.

---

## 🌐 Language Selector (COMPLETED: Removed from UI)

**Status:** COMMENTED OUT - Language options removed from homepage
**Date:** 2026-05-11
**Files Affected:** `frontend/index.html`

### Description:
The language selector bar that appeared at the top of the homepage has been temporarily disabled. The bar allowed users to switch between three languages:

- 🇬🇧 **English** - Primary language
- 🇧🇩 **বাংলা** (Bengali) - Bengali language option
- 🇯🇵 **日本語** (Japanese) - Japanese language option

### Current State:
- ✅ Language selector code has been commented out in HTML
- ✅ Homepage displays without language options
- ✅ Navigation positioning adjusted (now starts from top)

### Implementation Notes:
**Original Location:** Lines 238-245 in `frontend/index.html`

```html
<!-- Language Selector Bar - TBD: See TBD.md for details -->
<!--
<div class="fixed top-0 left-0 right-0 z-[60] bg-surface/95 backdrop-blur-md border-b border-outline-variant/20 py-2 px-8 animate-fade-in">
  <div class="max-w-7xl mx-auto flex justify-end gap-8">
    <a href="#" class="text-[11px] font-semibold text-primary tracking-[0.2em] uppercase hover:opacity-60 transition-opacity">English</a>
    <a href="#" class="text-[11px] font-medium text-secondary tracking-[0.2em] uppercase hover:text-primary transition-colors">বাংলা</a>
    <a href="#" class="text-[11px] font-medium text-secondary tracking-[0.2em] uppercase hover:text-primary transition-colors">日本語</a>
  </div>
</div>
-->
```

### Future Requirements:
When implementing multi-language support, consider:

#### Content Translation:
- [ ] Translate all homepage content to Bengali (বাংলা)
- [ ] Translate all homepage content to Japanese (日本語)
- [ ] Create translation files or database structure
- [ ] Maintain consistent terminology across languages

#### Technical Implementation:
- [ ] Choose internationalization approach:
  - **Option A:** Separate HTML files for each language (`index-bn.html`, `index-ja.html`)
  - **Option B:** JSON-based content loading with language detection
  - **Option C:** Server-side language detection and routing
  - **Option D:** JavaScript-based content switching

- [ ] Implement language detection:
  - Browser language preference detection
  - Manual language override
  - Remember user's language choice (cookies/localStorage)

- [ ] Ensure proper font support:
  - Bengali: Verify Unicode rendering for Bengali script
  - Japanese: Confirm CJK character display and fonts

#### Navigation & Routing:
- [ ] Update navigation to include language switcher
- [ ] Handle URL structure for multi-language site
- [ ] Ensure SEO-friendly URLs for each language
- [ ] Add hreflang tags for search engines

#### Design Considerations:
- [ ] Test layout with different text lengths in each language
- [ ] Ensure right-to-left (RTL) support if needed in future
- [ ] Optimize font loading for multi-language character sets
- [ ] Maintain consistent visual design across languages

#### Content Management:
- [ ] Update admin interface to support multi-language content
- [ ] Create translation workflow for content updates
- [ ] Ensure all new content includes translations

### Testing Checklist:
When re-enabling the language selector, verify:
- [ ] All languages display correctly on different browsers
- [ ] Font rendering is proper for Bengali and Japanese characters
- [ ] Language switching works without page reload if using JS approach
- [ ] Mobile responsiveness maintained with language selector
- [ ] No broken links or missing translations
- [ ] SEO tags properly set for each language version

### Related Files:
- `frontend/index.html` - Homepage (language selector commented out)
- `frontend/contact.html` - Contact page (may need language support)
- `frontend/registration.html` - Registration page (may need language support)
- `admin/` - Admin interface (future multi-language content management)

### Priority:
**MEDIUM** - Nice to have for broader accessibility, but English-only version is fully functional.

### Dependencies:
- Content translation services
- Font optimization for multi-language character sets
- Potential CMS updates for multi-language support

### Estimated Effort:
2-3 weeks for full implementation including:
- Content translation
- Technical implementation
- Testing across languages
- Documentation updates

---

## Additional TBD Items

*Add future TBD items below following the same format...*

---

**Last Updated:** 2026-05-11
**Maintained By:** Development Team
**For Questions:** Refer to this file when commenting/uncommenting language-related code
