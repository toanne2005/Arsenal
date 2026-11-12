tailwind.config = {
    theme: {
        extend: {
            fontFamily: {
                orbitron: ['Orbitron', 'sans-serif'],
                rajdhani: ['Rajdhani', 'sans-serif'],
            },
            colors: {
                mecha: {
                    black: '#030303',
                    dark: '#0A0A0A',
                    panel: '#151515',
                    white: '#FFFFFF',
                    gray: '#888888',
                    gold: '#D4AF37',
                    goldGlow: '#FFD700'
                }
            },
            backgroundImage: {
                'tech-grid': 'linear-gradient(rgba(255, 255, 255, 0.03) 1px, transparent 1px), linear-gradient(90deg, rgba(255, 255, 255, 0.03) 1px, transparent 1px)',
                'gold-gradient': 'linear-gradient(135deg, #D4AF37 0%, #AA8529 100%)',
            }
        }
    }
};
