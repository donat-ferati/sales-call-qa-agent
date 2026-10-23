import './bootstrap';

document.addEventListener('alpine:init', () => {
    Alpine.data('callPlayer', () => ({
        selected: null,
        currentTime: 0,
        seek(id, time) {
            this.selected = id;
            const audio = this.$refs.audio;
            const apply = () => { audio.currentTime = time; };
            if (audio.readyState >= 1) apply();
            else audio.addEventListener('loadedmetadata', apply, { once: true });
            const transcript = this.$refs.transcript;
            const segment = transcript?.querySelector(`[data-segment="${id}"]`);
            if (!segment) return;

            const transcriptBounds = transcript.getBoundingClientRect();
            const segmentBounds = segment.getBoundingClientRect();
            const viewportTop = transcriptBounds.top + transcript.clientTop;
            const viewportBottom = viewportTop + transcript.clientHeight;
            const top = segmentBounds.top - viewportTop;
            const bottom = segmentBounds.bottom - viewportBottom;

            if (top < 0) transcript.scrollBy({ top, behavior: 'smooth' });
            else if (bottom > 0) transcript.scrollBy({ top: Math.min(top, bottom), behavior: 'smooth' });
        },
        timestamp(seconds) {
            return `${Math.floor(seconds / 60)}:${String(Math.floor(seconds % 60)).padStart(2, '0')}`;
        },
    }));
});
