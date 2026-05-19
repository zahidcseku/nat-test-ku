// Load resources from JSON file
document.addEventListener('DOMContentLoaded', async function() {
  try {
    const response = await fetch('data/resources.json');
    const data = await response.json();

    console.log('Resources data:', data);

    // Render resources grid
    renderResources(data.resources);

  } catch (error) {
    console.error('Error loading resources:', error);
  }
});

function renderResources(resources) {
  const container = document.getElementById('resources-container');
  if (!container) {
    console.log('Resources container not found');
    return;
  }

  container.innerHTML = '';

  // Create a grid layout
  resources.forEach(resource => {
    const item = createResourceItem(resource);
    container.appendChild(item);
  });
}

function createResourceItem(resource) {
  const item = document.createElement('div');
  item.className = 'group flex flex-col md:flex-row items-start md:items-center justify-between p-6 bg-surface-container-low hover:bg-surface-container-high transition-all cursor-pointer rounded-lg';

  // Icon
  const iconElement = document.createElement('div');
  iconElement.className = 'w-12 h-12 flex items-center justify-center bg-white text-primary rounded-lg';
  const icon = document.createElement('span');
  icon.className = 'material-symbols-outlined text-3xl';
  icon.textContent = resource.icon;
  iconElement.appendChild(icon);

  // Content
  const content = document.createElement('div');
  content.className = 'flex-1';
  const title = document.createElement('h4');
  title.className = 'text-xl text-primary font-semibold group-hover:underline';
  title.textContent = resource.title;

  const description = document.createElement('p');
  description.className = 'text-sm text-secondary mt-1';
  description.textContent = resource.description;

  content.appendChild(title);
  content.appendChild(description);

  // Actions
  const actions = document.createElement('div');
  actions.className = 'mt-4 md:mt-0 flex items-center gap-4';

  const link = document.createElement('a');
  link.href = resource.url;
  link.target = resource.type === 'link' ? '_blank' : '_self';
  link.className = 'material-symbols-outlined text-primary group-hover:translate-x-1 transition-transform';
  const linkIcon = document.createElement('span');
  linkIcon.className = 'material-symbols-outlined';
  linkIcon.textContent = resource.type === 'link' ? 'open_in_new' : 'download';
  link.appendChild(linkIcon);
  actions.appendChild(link);

  item.appendChild(iconElement);
  item.appendChild(content);
  item.appendChild(actions);

  // Make entire item clickable
  item.addEventListener('click', function(e) {
    if (e.target.tagName !== 'A' && !e.target.closest('a')) {
      if (resource.type === 'link') {
        window.open(resource.url, '_blank');
      } else {
        window.location.href = resource.url;
      }
    }
  });

  return item;
}
