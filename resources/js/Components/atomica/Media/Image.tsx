export default function Image({ src, className = '' }: { src: string; className?: string }) {
    return (
        <>
            <img className={className} src={src} />
        </>
    );
}
