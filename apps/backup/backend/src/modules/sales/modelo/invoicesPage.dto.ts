import { ApiProperty } from '@nestjs/swagger';
import { InvoiceListItemDto } from './invoicesListItem.dto';

export class InvoicesPageDto {
  @ApiProperty({ type: [InvoiceListItemDto] })
  items!: InvoiceListItemDto[];

  @ApiProperty({ example: 1 })
  page!: number;

  @ApiProperty({ example: 10 })
  perPage!: number;

  @ApiProperty({ example: 100 })
  total!: number;
}

