// Initialize Thickbox
function tb_init(domChunk) {
    const elements = document.querySelectorAll(domChunk);
    elements.forEach(element => {
        element.addEventListener('click', (e) => {
            if (e.button !== 0 || e.ctrlKey) return true; // Ignore non-left clicks or Ctrl+clicks
            const title = element.title || element.name || null;
            const url = element.href || element.alt;
            const group = element.rel || false;
            tb_show(title, url, group);
            element.blur();
            e.preventDefault(); // Prevent default link behavior
            return false;
        });
    });
}

// Show Thickbox overlay and window
function tb_show(caption, url, imageGroup) {
    try {
        // Create overlay and window if they don’t exist
        if (!document.getElementById('TB_overlay')) {
            const overlay = document.createElement('div');
            overlay.id = 'TB_overlay';
            overlay.classList.add('TB_overlayBG');
            overlay.addEventListener('click', tb_remove);
            document.body.appendChild(overlay);

            const windowDiv = document.createElement('div');
            windowDiv.id = 'TB_window';
            document.body.appendChild(windowDiv);
        }

        // Set default caption if none provided
        caption = caption || '';

        // Show loading indicator
        const loadDiv = document.createElement('div');
        loadDiv.id = 'TB_load';
        loadDiv.innerHTML = `<img src="${tb_pathToImage}" alt="Loading" />`;
        document.body.appendChild(loadDiv);

        // Check if the URL is an image
        const isImage = url.toLowerCase().match(/\.(jpg|jpeg|png|gif|bmp)$/);
        if (isImage) {
            handleImage(url, caption, imageGroup);
        } else {
            handleAjax(url, caption);
        }
    } catch (e) {
        console.error('Thickbox error:', e);
    }
}

// Handle image display
function handleImage(url, caption, imageGroup) {
    const imgPreloader = new Image();
    imgPreloader.onload = () => {
        imgPreloader.onload = null;
        const pagesize = tb_getPageSize();
        let imageWidth = imgPreloader.width;
        let imageHeight = imgPreloader.height;
        const maxWidth = pagesize[0] - 150;
        const maxHeight = pagesize[1] - 150;

        // Resize image if it exceeds viewport dimensions
        if (imageWidth > maxWidth) {
            imageHeight = imageHeight * (maxWidth / imageWidth);
            imageWidth = maxWidth;
        }
        if (imageHeight > maxHeight) {
            imageWidth = imageWidth * (maxHeight / imageHeight);
            imageHeight = maxHeight;
        }

        const TB_WIDTH = imageWidth + 30;
        const TB_HEIGHT = imageHeight + 60;

        const windowDiv = document.getElementById('TB_window');
        windowDiv.innerHTML = `
            <img id="TB_Image" src="${url}" width="${imageWidth}" height="${imageHeight}" alt="${caption}" />
            <div id="TB_caption">${caption}</div>
            <div id="TB_closeWindow"><a href="#" id="TB_closeWindowButton" title="Close"><strong>x</strong></a></div>
        `;

        document.getElementById('TB_closeWindowButton').addEventListener('click', tb_remove);
        tb_position(TB_WIDTH, TB_HEIGHT);
        document.getElementById('TB_load').remove();
        windowDiv.style.display = 'block';
    };
    imgPreloader.src = url;
}

// Handle AJAX content
function handleAjax(url, caption) {
    const params = new URLSearchParams(url.split('?')[1] || '');
    const TB_WIDTH = (parseInt(params.get('width')) || 630) + 30;
    const TB_HEIGHT = (parseInt(params.get('height')) || 440) + 40;
    const ajaxContentW = TB_WIDTH - 30;
    const ajaxContentH = TB_HEIGHT - 45;

    const windowDiv = document.getElementById('TB_window');
    windowDiv.innerHTML = `
        <div id="TB_title">
            <div id="TB_ajaxWindowTitle">${caption}</div>
            <div id="TB_closeAjaxWindow"><a href="#" id="TB_closeWindowButton"><strong>x</strong></a></div>
        </div>
        <div id="TB_ajaxContent" style="width: ${ajaxContentW}px; height: ${ajaxContentH}px;"></div>
    `;

    document.getElementById('TB_closeWindowButton').addEventListener('click', tb_remove);

    fetch(url + (url.includes('?') ? '&' : '?') + 'random=' + Date.now())
        .then(response => response.text())
        .then(data => {
            document.getElementById('TB_ajaxContent').innerHTML = data;
            tb_position(TB_WIDTH, TB_HEIGHT);
            document.getElementById('TB_load').remove();
            windowDiv.style.display = 'block';
            tb_init('#TB_ajaxContent a.thickbox'); // Re-initialize Thickbox links in AJAX content
        })
        .catch(error => console.error('Error loading content:', error));
}

// Remove Thickbox overlay and window
function tb_remove() {
    const windowDiv = document.getElementById('TB_window');
    const overlay = document.getElementById('TB_overlay');
    const loadDiv = document.getElementById('TB_load');
    if (windowDiv) windowDiv.style.display = 'none';
    if (overlay) overlay.style.display = 'none';
    if (loadDiv) loadDiv.remove();
    document.body.style.overflow = 'auto';
}

// Position the Thickbox window
function tb_position(width, height) {
    const windowDiv = document.getElementById('TB_window');
    if (windowDiv) {
        windowDiv.style.width = `${width}px`;
        windowDiv.style.height = `${height}px`;
        windowDiv.style.marginLeft = `-${width / 2}px`;
        windowDiv.style.marginTop = `-${height / 2}px`;
    }
}

// Get the page size
function tb_getPageSize() {
    const width = window.innerWidth || document.documentElement.clientWidth || document.body.clientWidth;
    const height = window.innerHeight || document.documentElement.clientHeight || document.body.clientHeight;
    return [width, height];
}

// Initialize Thickbox on DOM load
document.addEventListener('DOMContentLoaded', () => {
    tb_init('a.thickbox, area.thickbox, input.thickbox');
    const imgLoader = new Image();
    imgLoader.src = tb_pathToImage; // Assumes tb_pathToImage is globally defined
});