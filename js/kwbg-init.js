document.addEventListener("DOMContentLoaded", function() {
    document.querySelectorAll("audio[id^='kwbg_audio_']").forEach(el => {
        new Plyr(el, {
            controls: ["play", "progress", "current-time", "duration", "mute", "volume"]
        });
    });
});

console.log("KWBG Init Loaded (from file)");