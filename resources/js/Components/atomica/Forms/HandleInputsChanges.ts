import React from 'react';

export default function handleChange(
    e: React.ChangeEvent<HTMLInputElement>,
    data: object,
    callBack: (data: any) => void,
) {
    const { id, value } = e.target;
    const keys = id.split('.');
    const updatedData = { ...data };
    let nestedData: any = updatedData;
    keys.forEach((key: string, index: number) => {
        if (index === keys.length - 1) {
            nestedData[key as keyof typeof nestedData] = value /* as string */;
        } else {
            nestedData[key as keyof typeof nestedData] = { ...(nestedData[key as keyof typeof nestedData] as object) };
            nestedData = nestedData[key as keyof typeof nestedData];
        }
    });
    callBack(updatedData);
}
