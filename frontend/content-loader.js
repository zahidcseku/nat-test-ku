/**
 * Load and render content blocks from content.json
 * Safe DOM methods used to prevent XSS
 */

async function loadContent() {
  try {
    const response = await fetch('/data/content.json');
    const data = await response.json();

    // Find blocks by type
    const blocks = data.blocks || [];
    const heroBlock = blocks.find(b => b.type === 'hero' && b.is_active);
    const bannerBlock = blocks.find(b => b.type === 'banner' && b.is_active);
    const headingBlock = blocks.find(b => b.type === 'heading_text' && b.is_active);
    const cardBlocks = blocks.filter(b => b.type === 'card' && b.is_active);
    const footerBlock = blocks.find(b => b.type === 'footer' && b.is_active);

    // Render each section if the block exists
    if (heroBlock) renderHero(heroBlock.content);
    if (bannerBlock) renderBanner(bannerBlock.content);
    if (headingBlock) renderHeadingText(headingBlock.content);
    if (cardBlocks.length > 0) renderCards(cardBlocks);
    if (footerBlock) renderFooter(footerBlock.content);

  } catch (error) {
    console.error('Error loading content:', error);
  }
}

function renderHero(content) {
  // Update hero section - using textContent for security
  const heroTitle = document.querySelector('.hero-title');
  const heroDescription = document.querySelector('.hero-description');
  const heroCtaPrimary = document.querySelector('.hero-cta-primary');
  const heroCtaSecondary = document.querySelector('.hero-cta-secondary');

  if (heroTitle) heroTitle.textContent = content.slogan;
  if (heroDescription) heroDescription.textContent = content.description;
  if (heroCtaPrimary) {
    heroCtaPrimary.textContent = content.primary_link.label;
    heroCtaPrimary.href = content.primary_link.url;
  }
  if (heroCtaSecondary) {
    heroCtaSecondary.textContent = content.secondary_link.label;
    heroCtaSecondary.href = content.secondary_link.url;
  }
}

function renderBanner(content) {
  // Update banner section
  const bannerDate = document.querySelector('.banner-date');
  const bannerInfoLink = document.querySelector('.banner-info-link');
  const bannerRegisterLink = document.querySelector('.banner-register-link');

  if (bannerDate) bannerDate.textContent = content.exam_date;
  if (bannerInfoLink) bannerInfoLink.href = content.exam_info_url;
  if (bannerRegisterLink) bannerRegisterLink.href = content.registration_url;
}

function renderHeadingText(content) {
  // Update heading section
  const headingTitle = document.querySelector('.section-heading');
  const headingText = document.querySelector('.section-text');

  if (headingTitle) headingTitle.textContent = content.heading;
  if (headingText) headingText.textContent = content.body_text;
}

function renderCards(cardBlocks) {
  // Render cards using safe DOM methods
  const cardsContainer = document.querySelector('.cards-container');
  if (!cardsContainer) return;

  cardsContainer.innerHTML = '';

  cardBlocks.forEach(block => {
    const content = block.content;
    const card = document.createElement('div');
    card.className = 'content-card';

    // Create icon element
    const icon = document.createElement('div');
    icon.className = 'card-icon';
    icon.textContent = content.icon_name || 'info';

    // Create title
    const title = document.createElement('h3');
    title.className = 'card-title';
    title.textContent = content.title;

    // Create description
    const desc = document.createElement('p');
    desc.className = 'card-description';
    desc.textContent = content.description;

    // Create link
    const link = document.createElement('a');
    link.href = content.link_url;
    link.className = 'card-link';
    link.textContent = 'Learn More →';

    card.appendChild(icon);
    card.appendChild(title);
    card.appendChild(desc);
    card.appendChild(link);

    cardsContainer.appendChild(card);
  });
}

function renderFooter(content) {
  // Update footer
  const footerCopyright = document.querySelector('.footer-copyright');
  const footerLinks = document.querySelector('.footer-links');

  if (footerCopyright) footerCopyright.textContent = content.copyright_text;

  if (footerLinks && content.links) {
    footerLinks.innerHTML = '';
    content.links.forEach(linkData => {
      const link = document.createElement('a');
      link.href = linkData.url;
      link.className = 'footer-link';
      link.textContent = linkData.label;
      footerLinks.appendChild(link);
    });
  }
}

// Load content when DOM is ready
if (document.readyState === 'loading') {
  document.addEventListener('DOMContentLoaded', loadContent);
} else {
  loadContent();
}
