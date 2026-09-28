import {
    AlertCircle,
    ArrowRight,
    CalendarOff,
    ChevronRight,
    CircleCheck,
    CircleX,
    Clock,
    createIcons,
    Eye,
    HandCoins,
    HeartHandshake,
    Lock,
    LoaderCircle,
    QrCode,
    RotateCcw,
    Search,
    ShieldCheck,
    Target,
    TrendingUp,
    UsersRound,
} from 'lucide';

/**
 * Iconos del sitio de donaciones.
 *
 * Se importan UNO A UNO a propósito. El bundle de la portada hace
 * `import { icons }`, que arrastra el catálogo entero de Lucide y pesa casi
 * 400 kB; aquí, listando solo los veinte que se usan, el bundle se queda en
 * unas decenas de kB. Quien entra a donar no tiene por qué descargar mil
 * iconos que no va a ver.
 *
 * Si añades un `<i data-lucide="algo">` en una plantilla de donaciones, el
 * icono tiene que aparecer también en esta lista o no se dibujará.
 */
const ICONOS = {
    AlertCircle,
    ArrowRight,
    CalendarOff,
    ChevronRight,
    CircleCheck,
    CircleX,
    Clock,
    Eye,
    HandCoins,
    HeartHandshake,
    Lock,
    LoaderCircle,
    QrCode,
    RotateCcw,
    Search,
    ShieldCheck,
    Target,
    TrendingUp,
    UsersRound,
};

/**
 * Sustituye por SVG los `<i data-lucide>` que queden en la página.
 *
 * Es idempotente: los que ya se convirtieron son `<svg>` y no vuelven a
 * tocarse. Por eso se puede volver a llamar cuando el JavaScript inserta
 * marcado nuevo, como hace la pantalla de resultado al cambiar de estado.
 */
export function pintarIconos() {
    try {
        createIcons({ icons: ICONOS, attrs: { 'stroke-width': 1.8 } });
    } catch (error) {
        // Un icono que no se dibuja no puede romper la página: al lado hay
        // texto que dice lo mismo.
    }
}
