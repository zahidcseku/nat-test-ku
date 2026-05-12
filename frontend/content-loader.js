/**
 * Load and render content blocks from content.json
 * Safe DOM methods used to prevent XSS
 */

async function loadContent() {
  console.log('🔄 Loading content from /data/content.json...');

  try {
    const response = await fetch('/data/content.json');
    if (!response.ok) {
      throw new Error(`HTTP ${response.status}: ${response.statusText}`);
    }

    const data = await response.json();
    console.log('✓ Content loaded:', data.blocks.length + ' blocks');

    // Find blocks by type
    const blocks = data.blocks || [];

    console.log('Processing blocks...');

    // Render each block type
    blocks.forEach(block => {
      if (!block.is_active) return;

      switch (block.type) {
        case 'hero_badge':
          renderHeroBadge(block.content);
          break;
        case 'hero_headline':
          renderHeroHeadline(block.content);
          break;
        case 'hero_description':
          renderHeroDescription(block.content);
          break;
        case 'hero_cta_primary':
          renderHeroCtaPrimary(block.content);
          break;
        case 'hero_cta_secondary':
          renderHeroCtaSecondary(block.content);
          break;
        case 'exam_ribbon':
          renderExamRibbon(block.content);
          break;
        case 'benefits_heading':
          renderBenefitsHeading(block.content);
          break;
        case 'benefits_description':
          renderBenefitsDescription(block.content);
          break;
        case 'resources_heading':
          renderResourcesHeading(block.content);
          break;
        case 'resource_card':
          renderResourceCard(block.content);
          break;
        case 'support_heading':
          renderSupportHeading(block.content);
          break;
        case 'support_description':
          renderSupportDescription(block.content);
          break;
        case 'support_contact':
          renderSupportContact(block.content);
          break;
        case 'footer_copyright':
          renderFooterCopyright(block.content);
          break;
        case 'footer_links':
          renderFooterLinks(block.content);
          break;
      }
    });

    console.log('✅ All content rendered successfully!');

  } catch (error) {
    console.error('❌ Error loading content:', error);
    // Show error on page
    const errorDiv = document.createElement('div');
    errorDiv.style.cssText = 'position: fixed; top: 20px; right: 20px; background: #ff4444; color: white; padding: 10px; border-radius: 5px; z-index: 9999;';
    errorDiv.textContent = 'Content loading failed: ' + error.message;
    document.body.appendChild(errorDiv);
  }
}

// Hero badge
function renderHeroBadge(content) {
  const badge = document.querySelector('.min-h-\\[100vh\\] .animate-fade-in-up.opacity-0.delay-100 span.text-primary\\/80.text-\\[10px\\]');
  if (badge) {
    badge.textContent = content.text;
    console.log('✓ Updated hero badge');
  }
}

// Hero headline - preserve HTML structure for styling
function renderHeroHeadline(content) {
  const headline = document.querySelector('.min-h-\\[100vh\\] .animate-fade-in-up.opacity-0.delay-200.font-serif.text-5xl');
  if (headline && content.full_html) {
    headline.innerHTML = content.full_html;
    console.log('✓ Updated hero headline');
  }
}

// Hero description
function renderHeroDescription(content) {
  const description = document.querySelector('.min-h-\\[100vh\\] .animate-fade-in-up.opacity-0.delay-300.text-base');
  if (description) {
    // Use HTML if available (for links), otherwise use plain text
    if (content.html) {
      description.innerHTML = content.html;
    } else {
      description.textContent = content.text;
    }
    console.log('✓ Updated hero description');
  }
}

// Hero primary CTA
function renderHeroCtaPrimary(content) {
  const cta = document.querySelector('.min-h-\\[100vh\\] .animate-fade-in-up.opacity-0.delay-400 .btn-magnetic.bg-primary');
  if (cta) {
    cta.href = content.url;
    const span = cta.querySelector('span');
    if (span) span.textContent = content.label;

    const icon = cta.querySelector('.material-symbols-outlined');
    if (icon && content.icon) icon.textContent = content.icon;

    console.log('✓ Updated hero primary CTA');
  }
}

// Hero secondary CTA
function renderHeroCtaSecondary(content) {
  const ctas = document.querySelectorAll('.min-h-\\[100vh\\] .animate-fade-in-up.opacity-0.delay-400 .btn-magnetic');
  if (ctas.length > 1) {
    const cta = ctas[1]; // Second button
    cta.href = content.url;

    // Open PDFs in a new tab
    if (content.url && content.url.toLowerCase().endsWith('.pdf')) {
      cta.setAttribute('target', '_blank');
      cta.setAttribute('rel', 'noopener noreferrer');
    }

    const span = cta.querySelector('span');
    if (span) span.textContent = content.label;

    const icon = cta.querySelector('.material-symbols-outlined');
    if (icon && content.icon) icon.textContent = content.icon;

    console.log('✓ Updated hero secondary CTA');
  }
}

// Exam ribbon
function renderExamRibbon(content) {
  const dateEl = document.querySelector('.relative.z-20 .bg-primary .font-serif.text-3xl span.font-semibold');
  if (dateEl) {
    dateEl.textContent = content.exam_date;
    console.log('✓ Updated exam date');
  }

  const statusEl = document.querySelector('.relative.z-20 .bg-primary .text-white\\/50.text-sm');
  if (statusEl) {
    statusEl.textContent = content.registration_status;
  }

  const infoLink = document.querySelector('.relative.z-20 .bg-primary a[href*="resources"]');
  if (infoLink) {
    infoLink.href = content.exam_info_url;
  }

  const registerLink = document.querySelector('.relative.z-20 .bg-primary a.bg-accent');
  if (registerLink) {
    registerLink.href = content.registration_url;
  }

  console.log('✓ Updated exam ribbon');
}

// Benefits heading - preserve HTML structure
function renderBenefitsHeading(content) {
  const heading = document.querySelector('.py-32.bg-surface .reveal.mb-20 .font-serif.text-3xl');
  if (heading && content.full_html) {
    heading.innerHTML = content.full_html;
    console.log('✓ Updated benefits heading');
  }
}

// Benefits description
function renderBenefitsDescription(content) {
  const desc = document.querySelector('.py-32.bg-surface .reveal.mb-20 div.text-secondary');
  if (desc) {
    // Use HTML if available (for links), otherwise use plain text
    if (content.html) {
      desc.innerHTML = content.html;
    } else {
      desc.textContent = content.text;
    }
    console.log('✓ Updated benefits description');
  }
}

// Resources heading - preserve HTML structure
function renderResourcesHeading(content) {
  const heading = document.querySelector('.py-32.bg-surface-container-low .reveal .font-serif.text-5xl');
  if (heading && content.full_html) {
    heading.innerHTML = content.full_html;
    console.log('✓ Updated resources heading');
  }
}

// Resource cards - needs to match by title
function renderResourceCard(content) {
  const cards = document.querySelectorAll('.py-32.bg-surface-container-low .space-y-4 .group.block.p-8');
  cards.forEach(card => {
    const titleEl = card.querySelector('.font-serif.text-xl');
    if (titleEl && titleEl.textContent === content.title) {
      // Update badge
      const badgeEl = card.querySelector('.text-\\[10px\\]');
      if (badgeEl) badgeEl.textContent = content.badge_type;

      // Update title
      titleEl.textContent = content.title;

      // Update link
      card.href = content.url;

      // Open PDFs in a new tab
      if (content.url && content.url.toLowerCase().endsWith('.pdf')) {
        card.setAttribute('target', '_blank');
        card.setAttribute('rel', 'noopener noreferrer');
      }

      // Update icon
      const iconEl = card.querySelector('.material-symbols-outlined');
      if (iconEl && content.icon) iconEl.textContent = content.icon;

      console.log('✓ Updated resource card:', content.title);
    }
  });
}

// Support heading - preserve HTML structure
function renderSupportHeading(content) {
  const heading = document.querySelector('.bg-primary.p-14 .font-serif.text-4xl');
  if (heading && content.full_html) {
    heading.innerHTML = content.full_html;
    console.log('✓ Updated support heading');
  }
}

// Support description
function renderSupportDescription(content) {
  const desc = document.querySelector('.bg-primary.p-14 .text-white\\/60');
  if (desc) {
    // Use HTML if available (for links), otherwise use plain text
    if (content.html) {
      desc.innerHTML = content.html;
    } else {
      desc.textContent = content.text;
    }
    console.log('✓ Updated support description');
  }
}

// Support contact (phone and email)
function renderSupportContact(content) {
  const contactGroups = document.querySelectorAll('.bg-primary.p-14 .space-y-6 .flex');
  contactGroups.forEach(group => {
    const labelEl = group.querySelector('.text-white\\/60.text-xs');
    if (labelEl && labelEl.textContent === content.label) {
      const valueEl = group.querySelector('.text-white.font-medium');
      if (valueEl) {
        valueEl.textContent = content.value;
      }

      const iconEl = group.querySelector('.material-symbols-outlined');
      if (iconEl && content.icon) iconEl.textContent = content.icon;

      console.log('✓ Updated support contact:', content.label);
    }
  });
}

// Footer copyright
function renderFooterCopyright(content) {
  const copyright = document.querySelector('footer .text-white\\/50.text-sm.leading-relaxed');
  if (copyright) {
    copyright.innerHTML = content.text.replace('\n', '<br>');
    console.log('✓ Updated footer copyright');
  }
}

// Footer links
function renderFooterLinks(content) {
  const linksContainer = document.querySelector('footer .max-w-7xl .flex.flex-wrap.md\\:justify-end');
  if (linksContainer && content.links) {
    linksContainer.innerHTML = '';
    content.links.forEach(linkData => {
      const link = document.createElement('a');
      link.href = linkData.url;
      link.className = 'text-white/50 hover:text-accent transition-colors text-sm tracking-wide';
      link.textContent = linkData.label;
      linksContainer.appendChild(link);
    });
    console.log('✓ Updated footer links');
  }
}

// Load content when DOM is ready
if (document.readyState === 'loading') {
  document.addEventListener('DOMContentLoaded', loadContent);
} else {
  loadContent();
}
