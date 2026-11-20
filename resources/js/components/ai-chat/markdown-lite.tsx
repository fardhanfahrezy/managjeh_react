import { Fragment, type ReactNode } from 'react';

type Block =
    | { type: 'heading'; text: string }
    | { type: 'paragraph'; lines: string[] }
    | { type: 'ul'; items: string[] }
    | { type: 'ol'; items: string[] };

function renderInline(text: string): ReactNode[] {
    const tokens = text.split(/(\*\*[^*]+\*\*|`[^`]+`)/g);

    return tokens.map((token, index) => {
        if (
            token.startsWith('**') &&
            token.endsWith('**') &&
            token.length > 4
        ) {
            return (
                <strong key={index} className="font-semibold text-foreground">
                    {token.slice(2, -2)}
                </strong>
            );
        }

        if (token.startsWith('`') && token.endsWith('`') && token.length > 2) {
            return (
                <code
                    key={index}
                    className="rounded bg-muted px-1 py-0.5 text-[0.8125rem] font-medium text-foreground"
                >
                    {token.slice(1, -1)}
                </code>
            );
        }

        return <Fragment key={index}>{token}</Fragment>;
    });
}

function parseBlocks(content: string): Block[] {
    const rawLines = content.replace(/\r\n/g, '\n').split('\n');
    const blocks: Block[] = [];
    let currentParagraph: string[] = [];
    let currentUl: string[] = [];
    let currentOl: string[] = [];

    const flushAll = () => {
        if (currentParagraph.length > 0) {
            blocks.push({ type: 'paragraph', lines: currentParagraph });
            currentParagraph = [];
        }
        if (currentUl.length > 0) {
            blocks.push({ type: 'ul', items: currentUl });
            currentUl = [];
        }
        if (currentOl.length > 0) {
            blocks.push({ type: 'ol', items: currentOl });
            currentOl = [];
        }
    };

    for (const rawLine of rawLines) {
        const trimmed = rawLine.trim();

        if (trimmed === '') {
            flushAll();
            continue;
        }

        const headingMatch = trimmed.match(/^#{1,3}\s+(.+)$/);
        if (headingMatch) {
            flushAll();
            blocks.push({ type: 'heading', text: headingMatch[1] });
            continue;
        }

        const ulMatch = trimmed.match(/^[-*•]\s+(.+)$/);
        if (ulMatch) {
            if (currentParagraph.length > 0) {
                blocks.push({ type: 'paragraph', lines: currentParagraph });
                currentParagraph = [];
            }
            if (currentOl.length > 0) {
                blocks.push({ type: 'ol', items: currentOl });
                currentOl = [];
            }
            currentUl.push(ulMatch[1]);
            continue;
        }

        const olMatch = trimmed.match(/^\d+\.\s+(.+)$/);
        if (olMatch) {
            if (currentParagraph.length > 0) {
                blocks.push({ type: 'paragraph', lines: currentParagraph });
                currentParagraph = [];
            }
            if (currentUl.length > 0) {
                blocks.push({ type: 'ul', items: currentUl });
                currentUl = [];
            }
            currentOl.push(olMatch[1]);
            continue;
        }

        if (currentUl.length > 0 || currentOl.length > 0) {
            flushAll();
        }
        currentParagraph.push(trimmed);
    }

    flushAll();

    return blocks;
}

export function MarkdownLite({ content }: { content: string }) {
    const blocks = parseBlocks(content);

    return (
        <div className="max-w-[70ch] space-y-2.5 text-sm leading-relaxed text-foreground">
            {blocks.map((block, index) => {
                if (block.type === 'heading') {
                    return (
                        <h4
                            key={index}
                            className="pt-1 text-sm font-semibold tracking-tight text-foreground"
                        >
                            {renderInline(block.text)}
                        </h4>
                    );
                }

                if (block.type === 'ul') {
                    return (
                        <ul
                            key={index}
                            className="list-disc space-y-1 pl-5 text-foreground"
                        >
                            {block.items.map((item, itemIdx) => (
                                <li key={itemIdx}>{renderInline(item)}</li>
                            ))}
                        </ul>
                    );
                }

                if (block.type === 'ol') {
                    return (
                        <ol
                            key={index}
                            className="list-decimal space-y-1 pl-5 text-foreground"
                        >
                            {block.items.map((item, itemIdx) => (
                                <li key={itemIdx}>{renderInline(item)}</li>
                            ))}
                        </ol>
                    );
                }

                return (
                    <p key={index} className="whitespace-pre-line">
                        {block.lines.map((line, lineIdx) => (
                            <Fragment key={lineIdx}>
                                {lineIdx > 0 && <br />}
                                {renderInline(line)}
                            </Fragment>
                        ))}
                    </p>
                );
            })}
        </div>
    );
}
