function showPlyrPlayer(videoUrl, subtitleUrl = '') {
    const playerUrl = '/static/player/plyr/index.html?url=' + encodeURIComponent(videoUrl);
    if (subtitleUrl) {
        playerUrl += '&subtitle=' + encodeURIComponent(subtitleUrl);
    }
    
    const playerHtml = '<iframe border="0" src="' + playerUrl + '" width="100%" height="100%" marginWidth="0" frameSpacing="0" marginHeight="0" frameBorder="0" scrolling="no" vspale="0" allowfullscreen></iframe>';
    
    // Hiển thị player
    const playerContainer = document.getElementById('player-container');
    if (playerContainer) {
        playerContainer.innerHTML = playerHtml;
        playerContainer.style.display = 'block';
    } else {
        console.error('Player container not found');
    }
} 