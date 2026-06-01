import { ApiProperty } from '@nestjs/swagger';
import { ClientDto } from './client.dto';

export class ClientsPageDto {
  @ApiProperty({ type: [ClientDto] })
  items!: ClientDto[];

  @ApiProperty({ example: 10 })
  page!: number;

  @ApiProperty({ example: 10 })
  perPage!: number;

  @ApiProperty({ example: 100 })
  total!: number;
}

