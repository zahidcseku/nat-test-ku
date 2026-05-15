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
    let resourceCardCount = 0; // Track which resource card we are rendering

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
        case 'benefit_card_1':
          renderBenefitCard1(block.content);
          break;
        case 'benefit_card_2':
          renderBenefitCard2(block.content);
          break;
        case 'benefit_card_3':
          renderBenefitCard3(block.content);
          break;
        case 'benefit_card_4':
          renderBenefitCard4(block.content);
          break;
        case 'resources_heading':
          renderResourcesHeading(block.content);
          break;
        case 'resource_card':
          resourceCardCount++;
          renderResourceCard(block.content, resourceCardCount);
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
  const badge = document.querySelector('.hero-badge-text');
  if (badge) {
    badge.textContent = content.text;
    console.log('✓ Updated hero badge');
  }
}

// Hero headline - preserve HTML structure for styling
function renderHeroHeadline(content) {
  const headline = document.querySelector('.hero-headline-text');
  if (headline && content.full_html) {
    headline.innerHTML = DOMPurify.sanitize(content.full_html);
    console.log('✓ Updated hero headline');
  }
}

// Hero description
function renderHeroDescription(content) {
  const description = document.querySelector('.hero-description-content');
  if (description) {
    // Use HTML if available (for links), otherwise use plain text
    if (content.html) {
      description.innerHTML = DOMPurify.sanitize(content.html);
    } else {
      description.textContent = content.text;
    }
    console.log('✓ Updated hero description');
  }
}

// Hero primary CTA
function renderHeroCtaPrimary(content) {
  const cta = document.querySelector('.hero-cta-primary-link');
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
  const cta = document.querySelector('.hero-cta-secondary-link');
  if (cta) {
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
  const dateEl = document.querySelector('.ribbon-exam-date');
  if (dateEl) {
    dateEl.textContent = content.exam_date;
    console.log('✓ Updated exam date');
  }

  const statusEl = document.querySelector('.ribbon-registration-status');
  if (statusEl) {
    statusEl.textContent = content.registration_status;
  }

  const infoLink = document.querySelector('.ribbon-info-url');
  if (infoLink) {
    infoLink.href = content.exam_info_url;
  }

  const registerLink = document.querySelector('.ribbon-registration-url');
  if (registerLink) {
    registerLink.href = content.registration_url;
  }

  console.log('✓ Updated exam ribbon');
}

// Benefits heading - preserve HTML structure
function renderBenefitsHeading(content) {
  const heading = document.querySelector('.benefits-heading');
  if (heading && content.full_html) {
    heading.innerHTML = DOMPurify.sanitize(content.full_html);
    console.log('✓ Updated benefits heading');
  }
}

// Benefits description
function renderBenefitCard1(content) {
  const card = document.querySelector('.benefit-card-1');
  if (card) {
    card.textContent = content.text;
    console.log('✓ Updated benefit card 1: Frequent Testing');
  }
}

function renderBenefitCard2(content) {
  const card = document.querySelector('.benefit-card-2');
  if (card) {
    card.textContent = content.text;
    console.log('✓ Updated benefit card 2: JLPT Equivalence');
  }
}

function renderBenefitCard3(content) {
  const card = document.querySelector('.benefit-card-3');
  if (card) {
    card.textContent = content.text;
    console.log('✓ Updated benefit card 3: Rapid Results');
  }
}

function renderBenefitCard4(content) {
  const card = document.querySelector('.benefit-card-4');
  if (card) {
    card.textContent = content.text;
    console.log('✓ Updated benefit card 4: Global Recognition');
  }
}

// Resources heading - preserve HTML structure
function renderResourcesHeading(content) {
  const heading = document.querySelector('.resources-heading');
  if (heading && content.full_html) {
    heading.innerHTML = DOMPurify.sanitize(content.full_html);
    console.log('✓ Updated resources heading');
  }
}

// Resource cards - targeted by index to allow title changes
function renderResourceCard(content, index) {
  const card = document.querySelector(`.resource-card-${index}`);
  if (card) {
    const titleEl = card.querySelector('.font-serif.text-xl');
    if (titleEl) {
        // Update badge
        const badgeEl = card.querySelector('.text-\\[10px\\]');
        if (badgeEl) badgeEl.textContent = content.badge_type;

        // Update title
        titleEl.textContent = content.title;

        // Update link
        card.href = content.url;

        // Handle target attribute from JSON or PDF auto-detection
        if (content.target) {
          card.setAttribute('target', content.target);
          if (content.target === '_blank') {
            card.setAttribute('rel', 'noopener noreferrer');
          }
        } else if (content.url && content.url.toLowerCase().endsWith('.pdf')) {
          card.setAttribute('target', '_blank');
          card.setAttribute('rel', 'noopener noreferrer');
        }

        // Update icon
        const iconEl = card.querySelector('.material-symbols-outlined');
        if (iconEl && content.icon) iconEl.textContent = content.icon;
        console.log('✓ Updated resource card:', content.title);
    }
  }
}

// Support heading - preserve HTML structure
function renderSupportHeading(content) {
  const heading = document.querySelector('.support-heading');
  if (heading && content.full_html) {
    heading.innerHTML = DOMPurify.sanitize(content.full_html);
    console.log('✓ Updated support heading');
  }
}

// Support description
function renderSupportDescription(content) {
  const desc = document.querySelector('.support-description-content');
  if (desc) {
    // Use HTML if available (for links), otherwise use plain text
    if (content.html) {
      desc.innerHTML = DOMPurify.sanitize(content.html);
    } else {
      desc.textContent = content.text;
    }
    console.log('✓ Updated support description');
  }
}

// Support contact (phone and email)
function renderSupportContact(content) {
  // Find which contact this is based on the label
  if (content.label === 'Call Us') {
    const valueEl = document.querySelector('.support-phone-value');
    const iconEl = document.querySelector('.support-phone-icon');

    if (valueEl) {
      valueEl.textContent = content.value;
    }
    if (iconEl && content.icon) {
      iconEl.textContent = content.icon;
    }
    console.log('✓ Updated support contact: Call Us');
  }

  if (content.label === 'Email Us') {
    const valueEl = document.querySelector('.support-email-value');
    const iconEl = document.querySelector('.support-email-icon');

    if (valueEl) {
      valueEl.textContent = content.value;
    }
    if (iconEl && content.icon) {
      iconEl.textContent = content.icon;
    }
    console.log('✓ Updated support contact: Email Us');
  }
}

// Footer copyright
function renderFooterCopyright(content) {
  const copyright = document.querySelector('.footer-copyright');
  if (copyright) {
    copyright.innerHTML = content.text.replace('\n', '<br>');
    console.log('✓ Updated footer copyright');
  }
}

// Footer links
function renderFooterLinks(content) {
  const linksContainer = document.querySelector('.footer-links-container');
  if (linksContainer && content.links) {
    linksContainer.innerHTML = '';
    content.links.forEach(linkData => {
      const link = document.createElement('a');
      link.href = linkData.url;
      link.className = 'text-secondary hover:text-primary transition-colors text-sm tracking-wide';
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
