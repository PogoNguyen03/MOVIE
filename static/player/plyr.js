// Plyr Player
MacPlayer.Html = '<div id="plyr-player"></div>';
MacPlayer.Show();

// Load Plyr CSS and JS
var link = document.createElement('link');
link.rel = 'stylesheet';
link.href = 'https://cdn.plyr.io/3.7.8/plyr.css';
document.head.appendChild(link);

var script = document.createElement('script');
script.src = 'https://cdn.plyr.io/3.7.8/plyr.js';
script.onload = function() {
    // Initialize Plyr
    const player = new Plyr('#plyr-player', {
        controls: [
            'play-large',
            'play',
            'progress',
            'current-time',
            'mute',
            'volume',
            'captions',
            'settings',
            'pip',
            'airplay',
            'fullscreen'
        ],
        settings: ['captions', 'quality', 'speed', 'loop'],
        speed: { selected: 1, options: [0.5, 0.75, 1, 1.25, 1.5, 1.75, 2] }
    });

    // Set video source
    const video = document.createElement('video');
    video.playsInline = true;
    video.controls = true;
    video.src = MacPlayer.PlayUrl;
    document.getElementById('plyr-player').appendChild(video);

    // Add subtitle if available
    if (MacPlayer.SubtitleUrl) {
        const track = document.createElement('track');
        track.kind = 'captions';
        track.label = 'Tiếng Việt';
        track.srclang = 'vi';
        track.src = MacPlayer.SubtitleUrl;
        video.appendChild(track);
    }
};
document.head.appendChild(script); 