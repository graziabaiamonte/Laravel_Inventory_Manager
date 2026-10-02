export function getUpdatedSelection(
    items: Array<{ id: string }>,
    checked: boolean,
    selectedItems: Array<string | number>,
) {
    if (!checked) {
        return [];
    }
    const newSelection: Array<any> = [];
    items.map(item => {
        if (!selectedItems.includes(item.id)) {
            newSelection.push(item.id);
        }
    });
    return newSelection;
}

export function getAllParams(url = window.location.href) {
    const obj: Record<string, any> = {};

    const paramsArray = url.match(/([^?=&]+)(=([^&]*))/g);

    if (paramsArray) {
        // Iterate the params array
        paramsArray.forEach(query => {
            // Split the array
            const strings = query.split('=');
            obj[strings[0]] = strings[1];
        });
    }

    // Return the object
    return obj;
}
