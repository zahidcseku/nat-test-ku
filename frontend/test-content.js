// Simple test to verify content loading is working
console.log('🧪 Testing content loading...');

fetch('/data/content.json')
  .then(response => {
    if (!response.ok) {
      throw new Error(`HTTP ${response.status}: ${response.statusText}`);
    }
    return response.json();
  })
  .then(data => {
    console.log('✅ Content loaded successfully:', data.blocks.length, 'blocks');

    // Test specific content blocks
    const heroBadge = data.blocks.find(b => b.type === 'hero_badge');
    if (heroBadge) {
      console.log('✓ Hero badge should be:', heroBadge.content.text);
    }

    const examRibbon = data.blocks.find(b => b.type === 'exam_ribbon');
    if (examRibbon) {
      console.log('✓ Exam date should be:', examRibbon.content.exam_date);
      console.log('✓ Registration status should be:', examRibbon.content.registration_status);
    }

    const resourceCards = data.blocks.filter(b => b.type === 'resource_card');
    console.log('✓ Resource cards:', resourceCards.length);
    resourceCards.forEach(card => {
      console.log(`  - ${card.content.badge_type}: ${card.content.title}`);
    });

    // Test DOM selectors
    console.log('🔍 Testing DOM selectors...');

    const badgeEl = document.querySelector('.hero-badge-text');
    console.log('  .hero-badge-text found:', !!badgeEl, 'Current value:', badgeEl?.textContent);

    const headlineEl = document.querySelector('.hero-headline-text');
    console.log('  .hero-headline-text found:', !!headlineEl, 'Current value:', headlineEl?.textContent);

    const examDateEl = document.querySelector('.ribbon-exam-date');
    console.log('  .ribbon-exam-date found:', !!examDateEl, 'Current value:', examDateEl?.textContent);

    const resourceCard1 = document.querySelector('.resource-card-1');
    console.log('  .resource-card-1 found:', !!resourceCard1);

    console.log('✅ Content loading test complete!');
  })
  .catch(error => {
    console.error('❌ Error loading content:', error);
  });
