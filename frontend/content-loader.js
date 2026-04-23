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
    const heroBlock = blocks.find(b => b.type === 'hero' && b.is_active);
    const bannerBlock = blocks.find(b => b.type === 'banner' && b.is_active);
    const headingBlock = blocks.find(b => b.type === 'heading_text' && b.is_active);
    const cardBlocks = blocks.filter(b => b.type === 'card' && b.is_active);
    const footerBlock = blocks.find(b => b.type === 'footer' && b.is_active);

    console.log('Found blocks:', {
      hero: !!heroBlock,
      banner: !!bannerBlock,
      heading: !!headingBlock,
      cards: cardBlocks.length,
      footer: !!footerBlock
    });

    // Render each section if the block exists
    if (heroBlock) {
      console.log('Updating hero section...');
      renderHero(heroBlock.content);
    }
    if (bannerBlock) {
      console.log('Updating banner section...');
      renderBanner(bannerBlock.content);
    }
    if (headingBlock) {
      console.log('Updating heading section...');
      renderHeadingText(headingBlock.content);
    }
    if (cardBlocks.length > 0) {
      console.log('Updating cards section...');
      renderCards(cardBlocks);
    }
    if (footerBlock) {
      console.log('Updating footer section...');
      renderFooter(footerBlock.content);
    }

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

function renderHero(content) {
  console.log('Rendering hero:', content);

  // Update hero section
  const heroTitle = document.querySelector('.hero-title');
  const heroDescription = document.querySelector('.hero-description');
  const heroCtaPrimary = document.querySelector('.hero-cta-primary');
  const heroCtaSecondary = document.querySelector('.hero-cta-secondary');

  console.log('Elements found:', {
    title: !!heroTitle,
    description: !!heroDescription,
    ctaPrimary: !!heroCtaPrimary,
    ctaSecondary: !!heroCtaSecondary
  });

  if (heroTitle) {
    heroTitle.textContent = content.slogan;
    console.log('✓ Updated title to:', content.slogan);
  }
  if (heroDescription) {
    heroDescription.textContent = content.description;
    console.log('✓ Updated description to:', content.description);
  }

  // Update links while preserving HTML structure (spans, icons)
  if (heroCtaPrimary) {
    heroCtaPrimary.href = content.primary_link.url;
    // Find the first span and update its text
    const span = heroCtaPrimary.querySelector('span');
    if (span) {
      span.textContent = content.primary_link.label;
    } else {
      // Fallback: replace entire content if no span found
      heroCtaPrimary.innerHTML = `<span>${content.primary_link.label}</span>`;
    }
    console.log('✓ Updated primary CTA to:', content.primary_link.label);
  }

  if (heroCtaSecondary) {
    heroCtaSecondary.href = content.secondary_link.url;
    const span = heroCtaSecondary.querySelector('span');
    if (span) {
      span.textContent = content.secondary_link.label;
    } else {
      heroCtaSecondary.innerHTML = `<span>${content.secondary_link.label}</span>`;
    }
    console.log('✓ Updated secondary CTA to:', content.secondary_link.label);
  }
}

function renderBanner(content) {
  console.log('Rendering banner:', content);

  // Update banner section
  const bannerDate = document.querySelector('.banner-date');
  const bannerInfoLink = document.querySelector('.banner-info-link');
  const bannerRegisterLink = document.querySelector('.banner-register-link');

  console.log('Banner elements found:', {
    date: !!bannerDate,
    infoLink: !!bannerInfoLink,
    registerLink: !!bannerRegisterLink
  });

  if (bannerDate) {
    bannerDate.textContent = content.exam_date;
    console.log('✓ Updated date to:', content.exam_date);
  }
  if (bannerInfoLink) {
    bannerInfoLink.href = content.exam_info_url;
    console.log('✓ Updated info link');
  }
  if (bannerRegisterLink) {
    bannerRegisterLink.href = content.registration_url;
    console.log('✓ Updated registration link');
  }
}

function renderHeadingText(content) {
  console.log('Rendering heading text:', content);

  // Update heading section
  const headingTitle = document.querySelector('.section-heading');
  const headingText = document.querySelector('.section-text');

  console.log('Heading elements found:', {
    heading: !!headingTitle,
    text: !!headingText
  });

  if (headingTitle) headingTitle.textContent = content.heading;
  if (headingText) headingText.textContent = content.body_text;
}

function renderCards(cardBlocks) {
  console.log('Rendering', cardBlocks.length, 'cards');

  // Get existing cards from the DOM
  const existingCards = document.querySelectorAll('.content-card');
  console.log('Found', existingCards.length, 'existing cards in HTML');

  if (existingCards.length === 0) {
    console.warn('⚠️ No existing cards found in HTML!');
    return;
  }

  // Update each existing card with data from JSON
  cardBlocks.forEach((block, index) => {
    const content = block.content;
    const card = existingCards[index];

    if (!card) {
      console.warn('⚠️ No card found for index', index);
      return;
    }

    // Update link href
    card.href = content.link_url;

    // Update title
    const titleEl = card.querySelector('.card-title');
    if (titleEl) {
      titleEl.textContent = content.title;
    }

    // Update description
    const descEl = card.querySelector('p:not(.card-title)');
    if (descEl) {
      descEl.textContent = content.description;
    }

    // Update icon if icon_name is provided
    if (content.icon_name) {
      const iconEl = card.querySelector('.material-symbols-outlined');
      if (iconEl) {
        iconEl.textContent = content.icon_name;
      }
    }

    console.log('✓ Updated card', index, ':', content.title);
  });

  console.log('✓ Updated', Math.min(cardBlocks.length, existingCards.length), 'cards');
}

function renderFooter(content) {
  console.log('Rendering footer:', content);

  // Update footer
  const footerCopyright = document.querySelector('.footer-copyright');
  const footerLinks = document.querySelector('.footer-links');

  console.log('Footer elements found:', {
    copyright: !!footerCopyright,
    links: !!footerLinks
  });

  if (footerCopyright) {
    footerCopyright.textContent = content.copyright_text;
    console.log('✓ Updated copyright');
  }

  if (footerLinks && content.links) {
    footerLinks.innerHTML = '';
    content.links.forEach(linkData => {
      const link = document.createElement('a');
      link.href = linkData.url;
      link.className = 'footer-link';
      link.textContent = linkData.label;
      footerLinks.appendChild(link);
    });
    console.log('✓ Updated', content.links.length, 'footer links');
  }
}

// Load content when DOM is ready
if (document.readyState === 'loading') {
  document.addEventListener('DOMContentLoaded', loadContent);
} else {
  loadContent();
}
